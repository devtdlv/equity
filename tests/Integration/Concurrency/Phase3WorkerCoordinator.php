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

            $payloadJson = json_encode([
                'action' => $worker['action'],
                'payload' => $worker['payload'],
                'result_path' => $resultPath,
                'barrier_path' => $this->barrier->path(),
                'start_at' => $startAt,
                'token' => $this->runToken,
            ], JSON_THROW_ON_ERROR);

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
        $payloadJson = json_encode([
            'action' => $action,
            'payload' => $payload,
            'result_path' => $resultPath,
            'barrier_path' => $this->barrier->path(),
            'start_at' => microtime(true) - 1,
            'token' => $this->runToken,
            'await_go_file' => false,
        ], JSON_THROW_ON_ERROR);

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
     * @param  array{process: resource, pid: int}  $worker
     */
    public function terminateOwnedWorker(array $worker): void
    {
        $status = proc_get_status($worker['process']);
        if (! is_array($status)) {
            return;
        }

        $pid = (int) ($status['pid'] ?? 0);
        $expected = (int) ($worker['pid'] ?? 0);
        if ($expected <= 0 || $pid !== $expected) {
            throw new RuntimeException('Refused to terminate a process whose PID does not match the owned worker.');
        }

        if ($status['running'] === true) {
            proc_terminate($worker['process']);
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($worker['process']);
                if (is_array($status) && $status['running'] === false) {
                    break;
                }
                usleep(50_000);
            }
        }
    }

    /**
     * @param  array<string, array{pipes: array{1: resource, 2: resource}, stdout: string, stderr: string}>  $processes
     * @return array<string, array{pipes: array{1: resource, 2: resource}, stdout: string, stderr: string}>
     */
    private function drainPipes(array $processes): array
    {
        foreach ($processes as $id => $proc) {
            $out = stream_get_contents($proc['pipes'][1]);
            $err = stream_get_contents($proc['pipes'][2]);
            if (is_string($out) && $out !== '') {
                $processes[$id]['stdout'] .= $out;
            }
            if (is_string($err) && $err !== '') {
                $processes[$id]['stderr'] .= $err;
            }
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
