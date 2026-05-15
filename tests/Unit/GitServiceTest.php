<?php

use App\Services\GitService;

test('clone returns output on success', function () {
    $service = new GitService(sshKeyPath: null);
    $tempDir  = sys_get_temp_dir() . '/bridge-clone-' . uniqid();

    $output = $service->clone('https://github.com/octocat/Hello-World.git', $tempDir, 'master');

    expect($output)->toBeString();
    expect(is_dir($tempDir))->toBeTrue();

    exec("rm -rf {$tempDir}");
});

test('clone throws RuntimeException on invalid repo', function () {
    $service = new GitService(sshKeyPath: null);
    $tempDir  = sys_get_temp_dir() . '/bridge-clone-fail-' . uniqid();

    expect(fn () => $service->clone(
        'https://github.com/nonexistent-org-99xyz/nonexistent-repo-99xyz.git',
        $tempDir,
        'main'
    ))->toThrow(\RuntimeException::class);
});

test('pull returns output for existing repo', function () {
    $service = new GitService(sshKeyPath: null);
    $tempDir  = sys_get_temp_dir() . '/bridge-pull-' . uniqid();

    $service->clone('https://github.com/octocat/Hello-World.git', $tempDir, 'master');
    $output = $service->pull($tempDir, 'master');

    expect($output)->toBeString();

    exec("rm -rf {$tempDir}");
});
