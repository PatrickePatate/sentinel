<?php

use App\Ssh\HostKeyFingerprint;
use App\Ssh\SshConnection;
use Tests\TestCase;

uses(TestCase::class);

// Generated with `ssh-keygen -t ed25519`; the expected value is what `ssh-keygen -lf` printed for it.
const HOST_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICnL+R25G8sKYA46N0IOIZ3IKbmWRuddHrXYGO/pF2pV';
const HOST_KEY_FINGERPRINT = 'SHA256:44jowkydoKmV4B857ppJZ2D6yhgeAamnG/3yfwnlfGE';

it('computes the same fingerprint as ssh-keygen', function () {
    expect(HostKeyFingerprint::of(HOST_KEY))->toBe(HOST_KEY_FINGERPRINT)
        ->and(HostKeyFingerprint::of(HOST_KEY.' comment'))->not->toBe('');
});

it('compares a fingerprint typed by a human leniently', function (string $typed) {
    expect(HostKeyFingerprint::matches($typed, HOST_KEY))->toBeTrue();
})->with([
    'exact' => HOST_KEY_FINGERPRINT,
    'surrounding whitespace' => ' '.HOST_KEY_FINGERPRINT."\n",
    'lowercase prefix' => 'sha256:44jowkydoKmV4B857ppJZ2D6yhgeAamnG/3yfwnlfGE',
]);

it('does not match another key', function () {
    expect(HostKeyFingerprint::matches('SHA256:nope', HOST_KEY))->toBeFalse();
});

it('keeps the old notation recognisable so existing pins can be upgraded', function () {
    expect(HostKeyFingerprint::legacy(HOST_KEY))->not->toBe(HOST_KEY_FINGERPRINT);
});

it('brackets IPv6 literals for the socket and leaves names and IPv4 alone', function () {
    expect(SshConnection::socketHost('2a01:4f8:120:643c::107'))->toBe('[2a01:4f8:120:643c::107]')
        ->and(SshConnection::socketHost('203.0.113.7'))->toBe('203.0.113.7')
        ->and(SshConnection::socketHost('srv.example.com'))->toBe('srv.example.com');
});
