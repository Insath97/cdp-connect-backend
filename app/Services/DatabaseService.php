<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class DatabaseService
{
    /**
     * Export the database to a SQL file.
     *
     * @return string Path to the generated SQL file
     * @throws \Exception
     */
    public function export(): string
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if ($config['driver'] !== 'mysql') {
            throw new \Exception("Export only supported for MySQL/MariaDB.");
        }

        $host = $config['host'];
        $port = $config['port'];
        $database = $config['database'];
        $username = $config['username'];
        $password = $config['password'];

        $filename = "backup-" . date('Y-m-d-H-i-s') . ".sql";
        $directory = storage_path('app/backups');

        if (!file_exists($directory)) {
            mkdir($directory, 0755, true);
        }

        $filePath = $directory . DIRECTORY_SEPARATOR . $filename;

        // Try to handle full paths for Laragon/Windows if not in PATH
        $mysqldump = 'mysqldump';
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $laragonPath = 'C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\mysqldump.exe';
            if (file_exists($laragonPath)) {
                $mysqldump = '"' . $laragonPath . '"';
            }
        }

        // Construct the mysqldump command
        $command = sprintf(
            '%s --user=%s %s --host=%s --port=%s %s > %s',
            $mysqldump,
            escapeshellarg($username),
            $password ? '--password=' . escapeshellarg($password) : '',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($database),
            escapeshellarg($filePath)
        );

        // Run the command
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            Log::error("Database export failed", ['output' => $output, 'command' => $command]);
            throw new \Exception("Database export failed with exit code {$returnVar}. Ensure mysqldump is in your PATH.");
        }

        return $filePath;
    }

    /**
     * Import a SQL file into the database.
     *
     * @param string $filePath
     * @return void
     * @throws \Exception
     */
    public function import(string $filePath): void
    {

        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        if ($config['driver'] !== 'mysql') {
            throw new \Exception("Import only supported for MySQL/MariaDB.");
        }

        $host = $config['host'];
        $port = $config['port'];
        $database = $config['database'];
        $username = $config['username'];
        $password = $config['password'];

        if (!file_exists($filePath)) {
            throw new \Exception("SQL file not found at: {$filePath}");
        }

        // Try to handle full paths for Laragon/Windows if not in PATH
        $mysql = 'mysql';
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $laragonPath = 'C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\mysql.exe';
            if (file_exists($laragonPath)) {
                $mysql = '"' . $laragonPath . '"';
            }
        }

        // Construct the mysql command
        $command = sprintf(
            '%s --user=%s %s --host=%s --port=%s %s < %s',
            $mysql,
            escapeshellarg($username),
            $password ? '--password=' . escapeshellarg($password) : '',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($database),
            escapeshellarg($filePath)
        );

        // Run the command and capture stderr
        $command .= ' 2>&1';
        $output = [];
        $returnVar = 0;
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            $errorMessage = implode("\n", $output);
            Log::error("Database import failed", [
                'output' => $errorMessage,
                'command' => $command
            ]);
            throw new \Exception("Database import failed: " . ($errorMessage ?: "Unknown error with exit code {$returnVar}."));
        }
    }
}
