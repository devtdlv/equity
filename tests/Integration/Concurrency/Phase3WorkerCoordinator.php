<?php

namespace Tests\Integration\Concurrency;

use RuntimeException;

/**
 * Multi-process coordinator for Phase 3.
 *
 * Wave start uses a shared absolute timestamp (avoids parent→worker IPC fragility on Windows).
 * Worker→parent held/ready signals use a disposable SQLite barrier with short-lived connections.
 * Optional go/release files remain available for single-worker control flows.
 */
final class Phase3WorkerCoordinator
{
    public function __construct(
        private readonly string $workDir,
        private readonly string $runToken,
        private readonly string $phpBinary,
        private readonly string $workerScript,
        private readonly array $childEnv,
        private readonly string $cwd,
        private readonly Phase3Barrier $barrier,
    ) {
        if (! is_dir($this->workDir) || ! str_contains(basename($this->workDir), $this->runToken)) {
            throw new RuntimeException('Phase 3 coordinator refused an unauthorized workdir.');
        }
        if (! is_file($this->workerScript)) {
            throw new RuntimeException('Phase 3 worker script is missing.');
        }
    }

    /**
     * @param  list<array{id: string, action: string, payload: array<string, mixed>}>  $workers
     * @return list<array{id: string, code: int, result: array<string, mixed>|null, output: string, pid: int|null}>
     */
    public function runBarrierWave(array $workers, int $timeoutSeconds = 90): array
    {
        $this->clearWaveArtifacts($workers);

        // Shared start: all workers spin until this absolute time, then race together.
        $startAt = microtime(true) + 2.0;

        $processes = [];
        foreach ($workers as $worker) {
            $id = $worker['id'];
            $payloadPath = $this->workDir.DIRECTORY_SEPARATOR.'payload_'.$id.'.json';
            $resultPath = $this->workDir.DIRECTORY_SEPARATOR.'result_'.$id.'.json';

            if (file_exists($payloadPath) || file_exists($resultPath)) {
                throw new RuntimeException("Phase 3 refused to overwrite existing worker artifact for {$id}.");
            }

            $wavePayload = [
                'action' => $worker['action'],
                'payload' => $worker['payload'],
                'result_path' => $resultPath,
                'barrier_path' => $this->barrier->path(),
                'start_at' => $startAt,
                'token' => $this->runToken,
            ];
            // Mirror EQUITY_PHASE3_SQLITE_TXN_MODE into the payload so workers can
            // activate IMMEDIATE even if a host strips unfamiliar env keys.
            if (isset($this->childEnv['EQUITY_PHASE3_SQLITE_TXN_MODE'])
                && is_string($this->childEnv['EQUITY_PHASE3_SQLITE_TXN_MODE'])
                && $this->childEnv['EQUITY_PHASE3_SQLITE_TXN_MODE'] !== ''
            ) {
                $wavePayload['sqlite_txn_mode'] = $this->childEnv['EQUITY_PHASE3_SQLITE_TXN_MODE'];
            }
            // Phase 4C.3: mirror production financial_immediate flag into the payload.
            if (isset($this->childEnv['EQUITY_FINANCIAL_IMMEDIATE'])
                && is_string($this->childEnv['EQUITY_FINANCIAL_IMMEDIATE'])
                && $this->childEnv['EQUITY_FINANCIAL_IMMEDIATE'] !== ''
            ) {
                $wavePayload['financial_immediate'] = in_array(
                    strtolower($this->childEnv['EQUITY_FINANCIAL_IMMEDIATE']),
                    ['1', 'true', 'yes', 'on'],
                    true,
                );
            }
            $payloadJson = json_encode($wavePayload, JSON_THROW_ON_ERROR);

            $handle = fopen($payloadPath, 'x+b');
            if ($handle === false) {
                throw new RuntimeException("Exclusive create failed for worker payload {$id}.");
            }
            fwrite($handle, $payloadJson);
            fclose($handle);

            $command = [$this->phpBinary, $this->workerScript, '--payload='.$payloadPath];
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $pipes = [];
            $process = proc_open($command, $descriptors, $pipes, $this->cwd, $this->childEnv);
            if (! is_resource($process)) {
                throw new RuntimeException("Failed to spawn Phase 3 worker {$id}.");
            }

            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $status = proc_get_status($process);
            $processes[$id] = [
                'process' => $process,
                'pipes' => $pipes,
                'stdout' => '',
                'stderr' => '',
                'pid' => is_array($status) ? ($status['pid'] ?? null) : null,
                'result_path' => $resultPath,
            ];
        }

        $deadline = microtime(true) + $timeoutSeconds;
        try {
            while (microtime(true) < $deadline) {
                $processes = $this->drainPipes($processes);
                $running = false;
                foreach ($processes as $proc) {
                    $status = proc_get_status($proc['process']);
                    if (is_array($status) && $status['running'] === true) {
                        $running = true;
                        break;
                    }
                }
                if (! $running) {
                    break;
                }
                usleep(10_000);
            }

            $processes = $this->drainPipes($processes);
            foreach ($processes as $id => $proc) {
                $status = proc_get_status($proc['process']);
                if (is_array($status) && $status['running'] === true) {
                    $this->terminateOwnedWorker($proc);
                    throw new RuntimeException("Worker {$id} exceeded the timeout and was terminated.");
                }
            }
        } finally {
            foreach ($processes as $proc) {
                $status = proc_get_status($proc['process']);
                if (is_array($status) && $status['running'] === true) {
                    $this->terminateOwnedWorker($proc);
                }
            }
        }

        $results = [];
        $processes = $this->drainPipes($processes);
        foreach ($processes as $id => $proc) {
            $stdout = $proc['stdout'].(stream_get_contents($proc['pipes'][1]) ?: '');
            $stderr = $proc['stderr'].(stream_get_contents($proc['pipes'][2]) ?: '');
            fclose($proc['pipes'][1]);
            fclose($proc['pipes'][2]);
            $code = proc_close($proc['process']);

            $decoded = null;
            if (is_file($proc['result_path'])) {
                $raw = file_get_contents($proc['result_path']);
                if (is_string($raw) && $raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (! is_array($decoded)) {
                        $decoded = null;
                    }
                }
            }

            $results[] = [
                'id' => $id,
                'code' => $code,
                'result' => $decoded,
                'output' => $stdout.$stderr,
                'pid' => $proc['pid'],
            ];
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{id: string, process: resource, pipes: array, pid: int, result_path: string, go_path: string, ready_key: string}
     */
    public function spawnWorker(string $id, string $action, array $payload): array
    {
        $payloadPath = $this->workDir.DIRECTORY_SEPARATOR.'payload_'.$id.'.json';
        $resultPath = $this->workDir.DIRECTORY_SEPARATOR.'result_'.$id.'.json';
        $goPath = $this->workDir.DIRECTORY_SEPARATOR.'go_'.$id.'_'.$this->runToken;
        $readyKey = 'ready_'.$id;

        foreach ([$payloadPath, $resultPath, $goPath] as $path) {
            if (file_exists($path)) {
                throw new RuntimeException("Phase 3 refused to overwrite existing artifact: {$path}");
            }
        }

        // Controlled workers start immediately; they signal progress via barrier held keys / release files.
        $spawnPayload = [
            'action' => $action,
            'payload' => $payload,
            'result_path' => $resultPath,
            'barrier_path' => $this->barrier->path(),
            'start_at' => microtime(true) - 1,
            'token' => $this->runToken,
            'await_go_file' => false,
        ];
        if (isset($this->childEnv['EQUITY_PHASE3_SQLITE_TXN_MODE'])
            && is_string($this->childEnv['EQUITY_PHASE3_SQLITE_TXN_MODE'])
            && $this->childEnv['EQUITY_PHASE3_SQLITE_TXN_MODE'] !== ''
        ) {
            $spawnPayload['sqlite_txn_mode'] = $this->childEnv['EQUITY_PHASE3_SQLITE_TXN_MODE'];
        }
        if (isset($this->childEnv['EQUITY_FINANCIAL_IMMEDIATE'])
            && is_string($this->childEnv['EQUITY_FINANCIAL_IMMEDIATE'])
            && $this->childEnv['EQUITY_FINANCIAL_IMMEDIATE'] !== ''
        ) {
            $spawnPayload['financial_immediate'] = in_array(
                strtolower($this->childEnv['EQUITY_FINANCIAL_IMMEDIATE']),
                ['1', 'true', 'yes', 'on'],
                true,
            );
        }
        $payloadJson = json_encode($spawnPayload, JSON_THROW_ON_ERROR);

        $handle = fopen($payloadPath, 'x+b');
        if ($handle === false) {
            throw new RuntimeException('Exclusive create failed for interrupt-worker payload.');
        }
        fwrite($handle, $payloadJson);
        fclose($handle);

        $command = [$this->phpBinary, $this->workerScript, '--payload='.$payloadPath];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, $this->cwd, $this->childEnv);
        if (! is_resource($process)) {
            throw new RuntimeException('Failed to spawn interrupt worker.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $status = proc_get_status($process);
        $pid = is_array($status) ? (int) ($status['pid'] ?? 0) : 0;
        if ($pid <= 0) {
            proc_terminate($process);
            proc_close($process);
            throw new RuntimeException('Interrupt worker PID could not be established.');
        }

        return [
            'id' => $id,
            'process' => $process,
            'pipes' => $pipes,
            'pid' => $pid,
            'result_path' => $resultPath,
            'go_path' => $goPath,
            'ready_key' => $readyKey,
        ];
    }

    public function waitForBarrierKey(string $key, string $expected, int $timeoutSeconds = 30): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if ($this->barrier->get($key) === $expected) {
                return;
            }
            usleep(5_000);
        }
        throw new RuntimeException("Timed out waiting for barrier {$key}={$expected}.");
    }

    /**
     * Wait for a previously spawned owned worker to exit, then collect its result.
     *
     * On timeout the worker is terminated (PID-validated), pipes/process are closed,
     * then the original timeout RuntimeException is thrown. Handles are always closed
     * so a later cleanupOwnedWorker() call is a safe no-op.
     *
     * @param  array{id: string, process: resource, pipes: array{1: resource, 2: resource}, pid: int, result_path: string}  $worker
     * @return array{id: string, code: int, result: array<string, mixed>|null, output: string, pid: int|null}
     */
    public function awaitOwnedWorker(array $worker, int $timeoutSeconds = 60): array
    {
        $id = (string) ($worker['id'] ?? 'unknown');
        $deadline = microtime(true) + $timeoutSeconds;
        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $code = -1;
        $failure = null;

        try {
            // Poll without draining pipes. On Windows, reading worker pipes causes
            // subsequent proc_get_status() calls to report running=false while the
            // child is still alive, which defeats timeout detection.
            while (microtime(true) < $deadline) {
                if (! $this->ownedWorkerIsRunning($worker)) {
                    break;
                }
                usleep(10_000);
            }

            if ($this->ownedWorkerIsRunning($worker)) {
                $timedOut = true;
                $this->terminateOwnedWorker($worker);
            }
        } catch (RuntimeException $e) {
            $failure = $e;
        } finally {
            // Capture running state before any pipe reads (Windows status quirk).
            $stillRunning = $this->ownedWorkerIsRunning($worker);
            [$stdout, $stderr] = $this->drainOwnedWorkerOutput($worker, $stdout, $stderr);
            $this->closeOwnedWorkerPipes($worker);
            // proc_close blocks while the child is alive; skip it when termination failed.
            if (! $stillRunning) {
                $code = $this->closeOwnedWorkerProcess($worker);
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        if ($timedOut) {
            throw new RuntimeException("Worker {$id} exceeded the timeout and was terminated.");
        }

        $decoded = null;
        $resultPath = is_string($worker['result_path'] ?? null) ? $worker['result_path'] : '';
        if ($resultPath !== '' && is_file($resultPath)) {
            $raw = file_get_contents($resultPath);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (! is_array($decoded)) {
                    $decoded = null;
                }
            }
        }

        return [
            'id' => $id,
            'code' => $code,
            'result' => $decoded,
            'output' => $stdout.$stderr,
            'pid' => $worker['pid'] ?? null,
        ];
    }

    /**
     * Terminate (when still running) + close for a spawned worker after sync failure.
     * Idempotent for already-closed handles. Ownership / failed-exit errors are
     * rethrown after best-effort pipe/handle close so callers never silently ignore them.
     *
     * @param  array{process?: resource, pipes?: array{1?: resource, 2?: resource}, pid?: int}  $worker
     */
    public function cleanupOwnedWorker(array $worker): void
    {
        $failure = null;

        try {
            if ($this->ownedWorkerIsRunning($worker)) {
                $this->terminateOwnedWorker($worker);
            }
        } catch (RuntimeException $e) {
            $failure = $e;
        }

        // Close pipes always. Avoid proc_close while the child is still running:
        // proc_close blocks until exit and can hang when termination did not complete.
        $this->closeOwnedWorkerPipes($worker);
        if (! $this->ownedWorkerIsRunning($worker)) {
            $this->closeOwnedWorkerProcess($worker);
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function createReleaseFile(string $path): void
    {
        if ($path === '' || ! str_contains(basename($path), $this->runToken)) {
            throw new RuntimeException('Refused to create release file outside the owned token scope.');
        }
        if (file_exists($path)) {
            throw new RuntimeException("Release file already exists: {$path}");
        }
        $handle = fopen($path, 'x+b');
        if ($handle === false) {
            throw new RuntimeException("Exclusive create failed for release file: {$path}");
        }
        fwrite($handle, 'release');
        fflush($handle);
        fclose($handle);
        clearstatcache(true, $path);
    }

    /**
     * Request termination of an owned running worker and verify it has exited.
     *
     * Idempotent when the process handle is already closed. PID ownership is
     * validated before any terminate signal. Does not treat the poll deadline
     * alone as successful exit.
     *
     * Limitation: relies on PHP proc_terminate() + proc_get_status(). On Windows
     * this usually stops cooperative children, but it is not a hard OS kill; if
     * the child ignores termination, this method throws rather than claiming success.
     *
     * @param  array{process?: mixed, pid?: int}  $worker
     */
    public function terminateOwnedWorker(array $worker): void
    {
        if (! $this->isValidProcessHandle($worker['process'] ?? null)) {
            return;
        }

        /** @var resource $process */
        $process = $worker['process'];
        $status = proc_get_status($process);
        if (! is_array($status)) {
            return;
        }

        $pid = (int) ($status['pid'] ?? 0);
        $expected = (int) ($worker['pid'] ?? 0);
        if ($expected <= 0 || $pid !== $expected) {
            throw new RuntimeException('Refused to terminate a process whose PID does not match the owned worker.');
        }

        if ($status['running'] !== true) {
            return;
        }

        proc_terminate($process);

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (! $this->isValidProcessHandle($process)) {
                return;
            }
            $status = proc_get_status($process);
            if (is_array($status) && $status['running'] === false) {
                return;
            }
            usleep(50_000);
        }

        if (! $this->isValidProcessHandle($process)) {
            return;
        }

        $final = proc_get_status($process);
        if (is_array($final) && $final['running'] === false) {
            return;
        }

        throw new RuntimeException(
            "Owned worker PID {$expected} did not exit after terminate was requested.",
        );
    }

    /**
     * @param  array{process?: mixed, pipes?: array{1?: mixed, 2?: mixed}}  $worker
     */
    private function ownedWorkerIsRunning(array $worker): bool
    {
        if (! $this->isValidProcessHandle($worker['process'] ?? null)) {
            return false;
        }

        $status = proc_get_status($worker['process']);

        return is_array($status) && $status['running'] === true;
    }

    private function isValidProcessHandle(mixed $process): bool
    {
        return is_resource($process);
    }

    /**
     * @param  array{pipes?: array{1?: mixed, 2?: mixed}}  $worker
     * @return array{0: string, 1: string}
     */
    private function drainOwnedWorkerOutput(array $worker, string $stdout, string $stderr): array
    {
        foreach ([1 => 'stdout', 2 => 'stderr'] as $fd => $label) {
            $pipe = $worker['pipes'][$fd] ?? null;
            if (! is_resource($pipe)) {
                continue;
            }
            $chunk = stream_get_contents($pipe);
            if (is_string($chunk) && $chunk !== '') {
                if ($label === 'stdout') {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }
        }

        return [$stdout, $stderr];
    }

    /**
     * Close pipes and process handle if still open. Idempotent.
     *
     * @param  array{process?: mixed, pipes?: array{1?: mixed, 2?: mixed}}  $worker
     */
    private function closeOwnedWorkerHandles(array $worker): int
    {
        $this->closeOwnedWorkerPipes($worker);

        return $this->closeOwnedWorkerProcess($worker);
    }

    /**
     * @param  array{pipes?: array{1?: mixed, 2?: mixed}}  $worker
     */
    private function closeOwnedWorkerPipes(array $worker): void
    {
        foreach ([1, 2] as $fd) {
            $pipe = $worker['pipes'][$fd] ?? null;
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }

    /**
     * @param  array{process?: mixed}  $worker
     */
    private function closeOwnedWorkerProcess(array $worker): int
    {
        if ($this->isValidProcessHandle($worker['process'] ?? null)) {
            return proc_close($worker['process']);
        }

        return -1;
    }

    /**
     * @param  array<string, array{pipes: array{1: resource, 2: resource}, stdout: string, stderr: string}>  $processes
     * @return array<string, array{pipes: array{1: resource, 2: resource}, stdout: string, stderr: string}>
     */
    private function drainPipes(array $processes): array
    {
        foreach ($processes as $id => $proc) {
            [$stdout, $stderr] = $this->drainOwnedWorkerOutput($proc, $proc['stdout'], $proc['stderr']);
            $processes[$id]['stdout'] = $stdout;
            $processes[$id]['stderr'] = $stderr;
        }

        return $processes;
    }

    /**
     * @param  list<array{id: string}>  $workers
     */
    private function clearWaveArtifacts(array $workers): void
    {
        foreach ($workers as $worker) {
            $id = $worker['id'];
            foreach ([
                $this->workDir.DIRECTORY_SEPARATOR.'payload_'.$id.'.json',
                $this->workDir.DIRECTORY_SEPARATOR.'result_'.$id.'.json',
                $this->workDir.DIRECTORY_SEPARATOR.'go_'.$id.'_'.$this->runToken,
            ] as $path) {
                if (is_file($path) && ! is_link($path) && str_contains(basename($path), $id)) {
                    @unlink($path);
                }
            }
        }
    }
}
