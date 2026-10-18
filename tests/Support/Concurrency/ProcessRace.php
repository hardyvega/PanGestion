<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProcessRace
{
    /** @param list<array<string, mixed>> $payloads @return list<array<string, mixed>> */
    public static function run(string $scenario, array $payloads): array
    {
        $processes = [];
        $files = [];
        $readyFiles = [];
        $readyClassId = random_int(100_000, 2_000_000_000);
        $goFile = tempnam(sys_get_temp_dir(), 'pangestion-go-');
        if ($goFile === false) {
            throw new RuntimeException('No se pudo crear la barrera temporal de concurrencia.');
        }
        @unlink($goFile);
        $files[] = $goFile;
        $root = dirname(__DIR__, 3);
        $worker = $root.'\\tests\\Support\\Concurrency\\worker.php';

        try {
            foreach ($payloads as $payload) {
                $file = tempnam(sys_get_temp_dir(), 'pangestion-race-');
                if ($file === false) {
                    throw new RuntimeException('No se pudo crear el payload temporal de concurrencia.');
                }

                file_put_contents($file, json_encode([
                    'scenario' => $scenario,
                    'payload' => $payload,
                    'go_file' => $goFile,
                    'ready_file' => $file.'.ready',
                    'ready_class_id' => $readyClassId,
                    'ready_object_id' => count($processes) + 1,
                ], JSON_THROW_ON_ERROR));
                $files[] = $file;
                $readyFiles[] = $file.'.ready';

                $command = sprintf(
                    '"%s" "%s" "%s"',
                    PHP_BINARY,
                    $worker,
                    $file,
                );
                $pipes = [];
                $process = proc_open($command, [
                    0 => ['file', 'NUL', 'r'],
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ], $pipes, $root);

                if (! is_resource($process)) {
                    throw new RuntimeException('No se pudo iniciar un worker de concurrencia.');
                }

                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $processes[] = [
                    'process' => $process,
                    'pipes' => $pipes,
                    'stdout' => '',
                    'stderr' => '',
                ];
            }

            self::waitForReady($processes, $readyClassId, count($payloads));
            file_put_contents($goFile, 'go');

            $deadline = microtime(true) + 45.0;
            $results = [];
            while (count($results) < count($processes)) {
                foreach ($processes as $index => &$workerState) {
                    self::drain($workerState);
                    if (isset($results[$index])) {
                        continue;
                    }

                    $status = proc_get_status($workerState['process']);
                    if (! $status['running']) {
                        $results[$index] = self::decodeResult($workerState, $status['exitcode']);
                    }
                }
                unset($workerState);

                if (microtime(true) > $deadline) {
                    throw new RuntimeException('La carrera concurrente excedio el tiempo limite.');
                }

                usleep(10_000);
            }

            foreach ($processes as $workerState) {
                proc_close($workerState['process']);
            }

            ksort($results);

            return array_values($results);
        } finally {
            foreach ($processes as $workerState) {
                if (is_resource($workerState['process'])) {
                    @proc_terminate($workerState['process']);
                    @proc_close($workerState['process']);
                }
            }
            foreach ($files as $file) {
                @unlink($file);
            }
            foreach ($readyFiles as $readyFile) {
                @unlink($readyFile);
            }
        }
    }

    /** @param array<int, array<string, mixed>> $processes */
    private static function waitForReady(array &$processes, int $readyClassId, int $workerCount): void
    {
        $deadline = microtime(true) + 30.0;
        $ready = [];

        while (count($ready) < count($processes)) {
            foreach ($processes as $index => &$workerState) {
                $readyObjectId = $index + 1;
                if (DB::scalar(
                    "SELECT 1 FROM pg_locks WHERE locktype = 'advisory' AND granted = true AND classid = ? AND objid = ? LIMIT 1",
                    [$readyClassId, $readyObjectId],
                ) !== null) {
                    $ready[$index] = true;
                }

                $status = proc_get_status($workerState['process']);
                if (! $status['running'] && ! isset($ready[$index])) {
                    throw new RuntimeException(
                        "El worker termino antes de la barrera.\n".$workerState['stdout']."\n".$workerState['stderr'],
                    );
                }
            }
            unset($workerState);

            if (microtime(true) > $deadline) {
                throw new RuntimeException('La barrera concurrente no recibio todos los workers.');
            }

            usleep(10_000);
        }
    }

    /** @param array<string, mixed> $workerState */
    private static function drain(array &$workerState): void
    {
        $stdout = fread($workerState['pipes'][1], 8192);
        $stderr = fread($workerState['pipes'][2], 8192);
        $workerState['stdout'] .= $stdout === false ? '' : $stdout;
        $workerState['stderr'] .= $stderr === false ? '' : $stderr;
    }

    /** @param array<string, mixed> $workerState @return array<string, mixed> */
    private static function decodeResult(array $workerState, int $exitCode): array
    {
        if (! preg_match('/RESULT ([^\r\n]+)/', $workerState['stdout'], $matches)) {
            throw new RuntimeException(
                "El worker no devolvio un resultado. Exit {$exitCode}.\n"
                .$workerState['stdout']."\n".$workerState['stderr'],
            );
        }

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }
}
