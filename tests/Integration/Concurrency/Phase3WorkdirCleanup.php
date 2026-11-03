<?php

namespace Tests\Integration\Concurrency;

/**
 * Fail-closed recursive cleanup for Phase 3 disposable workdirs.
 *
 * Never deletes through symlinks, directory junctions, or other path redirections.
 * Every deletion target must resolve inside the exact owned workdir for the run token.
 */
final class Phase3WorkdirCleanup
{
    /**
     * @return array{deleted: list<string>, left: list<string>, errors: list<string>}
     */
    public static function cleanup(string $workReal, string $token, string $tempReal, string $repoReal): array
    {
        $result = ['deleted' => [], 'left' => [], 'errors' => []];

        $workReal = self::normalize($workReal);
        $tempReal = self::normalize($tempReal);
        $repoReal = self::normalize($repoReal);

        if ($workReal === '' || $token === '' || $tempReal === '' || $repoReal === '') {
            $result['errors'][] = 'Refused to clean workdir: incomplete cleanup arguments.';

            return $result;
        }

        clearstatcache(true, $workReal);

        if (! file_exists($workReal) && ! is_link($workReal)) {
            return $result;
        }

        if (is_link($workReal) || self::isUnsafeRedirection($workReal)) {
            $result['left'][] = $workReal;
            $result['errors'][] = "Refused to clean workdir that is a symlink, junction, or redirection: {$workReal}";

            return $result;
        }

        if (! is_dir($workReal)) {
            $result['left'][] = $workReal;
            $result['errors'][] = "Refused to clean non-directory workdir path: {$workReal}";

            return $result;
        }

        $resolved = realpath($workReal);
        if ($resolved === false) {
            $result['left'][] = $workReal;
            $result['errors'][] = "Workdir could not be resolved: {$workReal}";

            return $result;
        }
        $resolved = self::normalize($resolved);

        if (! self::pathsEqual($resolved, $workReal)
            || ! self::pathsEqual(dirname($resolved), $tempReal)
            || ! str_contains(basename($resolved), $token)
            || self::isWithinDirectory($resolved, $repoReal)) {
            $result['errors'][] = "Refused to clean unverified workdir: {$workReal}";
            $result['left'][] = $workReal;

            return $result;
        }

        self::cleanDirectory($resolved, $resolved, $repoReal, $result);

        clearstatcache(true, $resolved);
        if (! is_dir($resolved) && ! is_link($resolved)) {
            return $result;
        }

        if (is_link($resolved) || self::isUnsafeRedirection($resolved)) {
            $result['left'][] = $resolved;
            $result['errors'][] = "Refused to remove workdir root after discovering redirection: {$resolved}";

            return $result;
        }

        $entries = @scandir($resolved);
        if ($entries === false) {
            $result['left'][] = $resolved;
            $result['errors'][] = "Could not re-scan workdir before removal: {$resolved}";

            return $result;
        }

        $remaining = array_values(array_filter(
            $entries,
            static fn (string $name): bool => $name !== '.' && $name !== '..',
        ));
        if ($remaining !== []) {
            $result['left'][] = $resolved;
            $result['errors'][] = "Refused to remove non-empty workdir after fail-closed cleanup: {$resolved}";

            return $result;
        }

        if (@rmdir($resolved)) {
            $result['deleted'][] = $resolved;
        } else {
            $result['left'][] = $resolved;
            $result['errors'][] = "Could not remove workdir: {$resolved}";
        }

        return $result;
    }

    /**
     * @param  array{deleted: list<string>, left: list<string>, errors: list<string>}  $result
     */
    private static function cleanDirectory(string $directoryReal, string $workReal, string $repoReal, array &$result): void
    {
        if (! self::isWithinDirectory($directoryReal, $workReal) || self::isWithinDirectory($directoryReal, $repoReal)) {
            $result['left'][] = $directoryReal;
            $result['errors'][] = "Refused to enter directory outside owned workdir scope: {$directoryReal}";

            return;
        }

        $entries = @scandir($directoryReal);
        if ($entries === false) {
            $result['left'][] = $directoryReal;
            $result['errors'][] = "Could not scan workdir directory: {$directoryReal}";

            return;
        }

        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            // Reject path components that could escape via traversal tricks.
            if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
                $result['errors'][] = "Refused to process unsafe directory entry name under {$directoryReal}";
                continue;
            }

            $child = $directoryReal.DIRECTORY_SEPARATOR.$name;
            clearstatcache(true, $child);

            if (is_link($child) || self::isUnsafeRedirection($child)) {
                $result['left'][] = $child;
                $result['errors'][] = "Refused to delete symlink, junction, or redirected path in workdir: {$child}";
                continue;
            }

            $childReal = realpath($child);
            if ($childReal === false) {
                $result['left'][] = $child;
                $result['errors'][] = "Refused to delete unresolved path in workdir: {$child}";
                continue;
            }
            $childReal = self::normalize($childReal);

            $expectedChild = $directoryReal.DIRECTORY_SEPARATOR.$name;
            if (! self::pathsEqual($childReal, $expectedChild)) {
                $result['left'][] = $child;
                $result['errors'][] = "Refused to delete path whose realpath escaped the literal child location: {$child}";
                continue;
            }

            if (! self::isWithinDirectory($childReal, $workReal)) {
                $result['left'][] = $child;
                $result['errors'][] = "Refused to delete path outside owned workdir: {$child}";
                continue;
            }

            if (self::isWithinDirectory($childReal, $repoReal)) {
                $result['left'][] = $child;
                $result['errors'][] = "Refused to delete path inside the repository: {$child}";
                continue;
            }

            if (is_dir($child) && ! is_link($child)) {
                self::cleanDirectory($childReal, $workReal, $repoReal, $result);

                clearstatcache(true, $childReal);
                if (is_link($childReal) || self::isUnsafeRedirection($childReal)) {
                    $result['left'][] = $childReal;
                    $result['errors'][] = "Refused to remove redirected subdirectory: {$childReal}";
                    continue;
                }

                $childEntries = @scandir($childReal);
                if ($childEntries === false) {
                    $result['left'][] = $childReal;
                    $result['errors'][] = "Could not re-scan subdirectory before removal: {$childReal}";
                    continue;
                }
                $childRemaining = array_values(array_filter(
                    $childEntries,
                    static fn (string $entry): bool => $entry !== '.' && $entry !== '..',
                ));
                if ($childRemaining !== []) {
                    $result['left'][] = $childReal;
                    $result['errors'][] = "Refused to remove non-empty subdirectory after fail-closed cleanup: {$childReal}";
                    continue;
                }

                if (@rmdir($childReal)) {
                    $result['deleted'][] = $childReal;
                } else {
                    $result['left'][] = $childReal;
                    $result['errors'][] = "Could not remove workdir subdirectory: {$childReal}";
                }

                continue;
            }

            if (is_file($child) && ! is_link($child) && ! is_dir($child)) {
                if (@unlink($childReal)) {
                    $result['deleted'][] = $childReal;
                } else {
                    $result['left'][] = $childReal;
                    $result['errors'][] = "Could not delete workdir file: {$childReal}";
                }

                continue;
            }

            $result['left'][] = $child;
            $result['errors'][] = "Refused to delete unrecognized workdir entry type: {$child}";
        }
    }

    public static function normalize(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    public static function pathsEqual(string $left, string $right): bool
    {
        $left = self::normalize($left);
        $right = self::normalize($right);

        if (PHP_OS_FAMILY === 'Windows') {
            return strcasecmp($left, $right) === 0;
        }

        return $left === $right;
    }

    public static function isWithinDirectory(string $path, string $directory): bool
    {
        $path = self::normalize($path);
        $directory = self::normalize($directory);

        if (self::pathsEqual($path, $directory)) {
            return true;
        }

        $prefix = $directory.DIRECTORY_SEPARATOR;
        if (PHP_OS_FAMILY === 'Windows') {
            return str_starts_with(strtolower($path), strtolower($prefix));
        }

        return str_starts_with($path, $prefix);
    }

    /**
     * Detect symlinks and Windows directory junctions / reparse redirections.
     *
     * A safe child directory/file must realpath to exactly parentReal + basename.
     * Junctions and symlinks typically resolve elsewhere and are rejected.
     */
    public static function isUnsafeRedirection(string $path): bool
    {
        clearstatcache(true, $path);

        if (is_link($path)) {
            return true;
        }

        if (! file_exists($path)) {
            return false;
        }

        $parent = dirname($path);
        $parentReal = realpath($parent);
        $pathReal = realpath($path);

        if ($parentReal === false || $pathReal === false) {
            // Existing path that cannot be canonicalized: fail closed.
            return true;
        }

        $expected = self::normalize($parentReal).DIRECTORY_SEPARATOR.basename($path);
        // Ordinary children realpath to parentReal/basename. Junctions/symlinks resolve elsewhere.
        // Do not use Windows readlink() alone: PHP may return the path itself for normal directories.
        if (! self::pathsEqual(self::normalize($pathReal), $expected)) {
            return true;
        }

        return false;
    }
}
