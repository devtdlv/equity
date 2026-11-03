<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Integration\Concurrency\Phase3Barrier;
use Tests\Integration\Concurrency\Phase3WorkdirCleanup;
use Tests\Integration\Concurrency\Phase3WorkerCoordinator;

/**
 * Focused lifecycle coverage for Phase 3 owned-worker await/cleanup.
 * Uses disposable temp resources and a short-lived PHP subprocess -- does not
 * open database/database.sqlite or boot Laravel.
 */
final class Phase3WorkerCoordinatorLifecycleTest extends TestCase
{
    private string $token;

    private string $workDir;

    private string $tempDir;

    private string $repoRoot;

    private string $barrierPath;

    private string $dummyWorkerScript;

    protected function setUp(): void
    {
        parent::setUp();

        $temp = realpath(sys_get_temp_dir());
        $this->assertNotFalse($temp);
        $this->tempDir = Phase3WorkdirCleanup::normalize($temp);

        $root = realpath(dirname(__DIR__, 3));
        $this->assertNotFalse($root);
        $this->repoRoot = Phase3WorkdirCleanup::normalize($root);

        $this->token = bin2hex(random_bytes(8));
        $this->workDir = $this->tempDir.DIRECTORY_SEPARATOR.'equity-phase3-work-'.$this->token;
        $this->assertTrue(mkdir($this->workDir, 0700), 'failed to create disposable Phase 3 workdir');

        $this->barrierPath = $this->workDir.DIRECTORY_SEPARATOR.'barrier-'.$this->token.'.sqlite';
        $handle = fopen($this->barrierPath, 'x+b');
        $this->assertNotFalse($handle);
        fclose($handle);

        $this->dummyWorkerScript = $this->workDir.DIRECTORY_SEPARATOR.'noop-worker-'.$this->token.'.php';
        $written = file_put_contents($this->dummyWorkerScript, "<?php\n// disposable coordinator constructor stub\n");
        $this->assertNotFalse($written);
    }

    protected function tearDown(): void
    {
        $workReal = realpath($this->workDir);
        if (is_string($workReal) && $workReal !== '') {
            Phase3WorkdirCleanup::cleanup(
                Phase3WorkdirCleanup::normalize($workReal),
                $this->token,
                $this->tempDir,
                $this->repoRoot,
            );
        }

        parent::tearDown();
    }

    #[Test]
    public function cleanup_after_await_timeout_is_idempotent_and_child_has_exited(): void
    {
        $coord = $this->coordinator();
        $worker = $this->spawnSleepWorker('timeout_worker', 30);
        $pid = (int) $worker['pid'];

        $thrown = null;
        try {
            $coord->awaitOwnedWorker($worker, 1);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        $this->assertSame(
            'Worker timeout_worker exceeded the timeout and was terminated.',
            $thrown->getMessage(),
        );
        $this->assertFalse(is_resource($worker['process']));
        $this->assertFalse(is_resource($worker['pipes'][1]));
        $this->assertFalse(is_resource($worker['pipes'][2]));

        // OS-level check: the child must have exited, not merely lost its PHP handle.
        $this->assertFalse(
            $this->isOsProcessAlive($pid),
            "Timed-out worker PID {$pid} must not still be alive after awaitOwnedWorker timeout.",
        );

        // Caller's exception path may invoke cleanup on the same handle after await closed it.
        $coord->cleanupOwnedWorker($worker);
        $coord->cleanupOwnedWorker($worker);
        $coord->terminateOwnedWorker($worker);
    }

    #[Test]
    public function cleanup_of_already_closed_handles_is_a_safe_noop(): void
    {
        $coord = $this->coordinator();
        $worker = $this->spawnSleepWorker('closed_worker', 1);

        // Let the short process finish, then close handles the same way await/cleanup would.
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($worker['process']);
            if (is_array($status) && $status['running'] === false) {
                break;
            }
            usleep(10_000);
        }

        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        proc_close($worker['process']);

        $this->assertFalse(is_resource($worker['process']));

        $coord->cleanupOwnedWorker($worker);
        $coord->terminateOwnedWorker($worker);
        $coord->cleanupOwnedWorker($worker);
    }

    #[Test]
    public function terminate_refuses_pid_mismatch_on_still_open_process(): void
    {
        $coord = $this->coordinator();
        $worker = $this->spawnSleepWorker('pid_guard', 30);

        $mismatched = $worker;
        $mismatched['pid'] = ((int) $worker['pid']) + 99999;

        $thrown = null;
        try {
            $coord->terminateOwnedWorker($mismatched);
        } catch (RuntimeException $e) {
            $thrown = $e;
        } finally {
            $coord->cleanupOwnedWorker($worker);
        }

        $this->assertNotNull($thrown);
        $this->assertSame(
            'Refused to terminate a process whose PID does not match the owned worker.',
            $thrown->getMessage(),
        );
        $this->assertFalse(
            $this->isOsProcessAlive((int) $worker['pid']),
            'Owned worker must be stopped after cleanup with the correct PID.',
        );
    }

    #[Test]
    public function cleanup_propagates_pid_mismatch_after_closing_pipes(): void
    {
        $coord = $this->coordinator();
        $worker = $this->spawnSleepWorker('cleanup_pid_guard', 30);

        $mismatched = $worker;
        $mismatched['pid'] = ((int) $worker['pid']) + 99999;

        $thrown = null;
        try {
            $coord->cleanupOwnedWorker($mismatched);
        } catch (RuntimeException $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        $this->assertSame(
            'Refused to terminate a process whose PID does not match the owned worker.',
            $thrown->getMessage(),
        );

        // Real owned handle must still be reclaimable with the correct PID.
        $coord->cleanupOwnedWorker($worker);
        $this->assertFalse(
            $this->isOsProcessAlive((int) $worker['pid']),
            'Owned worker must be stopped during cleanup with the correct PID.',
        );
    }

    private function coordinator(): Phase3WorkerCoordinator
    {
        return new Phase3WorkerCoordinator(
            workDir: $this->workDir,
            runToken: $this->token,
            phpBinary: PHP_BINARY,
            workerScript: $this->dummyWorkerScript,
            childEnv: [],
            cwd: $this->workDir,
            barrier: new Phase3Barrier($this->barrierPath),
        );
    }

    /**
     * @return array{id: string, process: resource, pipes: array{0: resource, 1: resource, 2: resource}, pid: int, result_path: string}
     */
    private function spawnSleepWorker(string $id, int $sleepSeconds): array
    {
        $resultPath = $this->workDir.DIRECTORY_SEPARATOR.'result_'.$id.'.json';
        // Use sleep() -- Windows usleep() cannot reliably wait multi-second durations.
        $seconds = max(1, $sleepSeconds);
        $command = [PHP_BINARY, '-r', 'sleep('.$seconds.');'];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($command, $descriptors, $pipes, $this->workDir, null);
        $this->assertIsResource($process, 'failed to spawn disposable sleep worker');

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $status = proc_get_status($process);
        $this->assertIsArray($status);
        $pid = (int) ($status['pid'] ?? 0);
        $this->assertGreaterThan(0, $pid);
        $this->assertTrue($status['running'] === true, 'sleep worker should still be running at spawn');

        return [
            'id' => $id,
            'process' => $process,
            'pipes' => $pipes,
            'pid' => $pid,
            'result_path' => $resultPath,
        ];
    }

    /**
     * OS-level liveness check independent of PHP process handles / pipe-drain quirks.
     */
    private function isOsProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $command = 'tasklist /FI "PID eq '.$pid.'" /NH';
            $output = [];
            $exitCode = 0;
            exec($command.' 2>NUL', $output, $exitCode);
            $joined = implode("\n", $output);

            return str_contains($joined, (string) $pid);
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        // Fallback when posix is unavailable: treat unknown as not alive for assertion safety.
        return false;
    }
}
