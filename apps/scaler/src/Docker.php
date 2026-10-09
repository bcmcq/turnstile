<?php

declare(strict_types=1);

namespace Scaler;

/**
 * Scales the worker service by cloning one existing worker container with `docker run` (same image, env,
 * command, networks, mounts and compose labels), or stopping the newest clones. See docs/adr/0001.
 *
 * Only containers carrying this project's compose labels are ever touched.
 */
final class Docker
{
    private function __construct(
        private readonly string $project,
        private readonly string $service,
        private readonly int $min,
        public readonly int $max,
    ) {}

    public static function fromEnv(): self
    {
        return new self(
            getenv('COMPOSE_PROJECT') ?: 'turnstile',
            getenv('WORKER_SERVICE') ?: 'worker',
            max(1, (int) (getenv('MIN_WORKERS') ?: 1)),
            (int) getenv('MAX_WORKERS') ?: throw new \RuntimeException('MAX_WORKERS is not set; compose.yaml provides it'),
        );
    }

    /** @return list<array{name: string, state: string, number: int}> */
    public function workers(): array
    {
        $lines = $this->run(['ps', '-a', '--filter', "label=com.docker.compose.project={$this->project}", '--filter', "label=com.docker.compose.service={$this->service}", '--format', '{{.Names}}|{{.State}}|{{.Label "com.docker.compose.container-number"}}']);
        $out = [];
        foreach ($lines as $line) {
            [$name, $state, $number] = array_pad(explode('|', $line), 3, '');
            if ('' === $name) {
                continue;
            }
            $out[] = ['name' => $name, 'state' => $state, 'number' => (int) $number];
        }
        usort($out, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);

        return $out;
    }

    /** @return array{target: int, before: int, after: int, started: list<string>, stopped: list<string>, workers: list<array{name: string, state: string, number: int}>} */
    public function scale(int $target): array
    {
        $target = max($this->min, min($this->max, $target));
        $workers = array_values(array_filter($this->workers(), static fn (array $w): bool => 'running' === $w['state']));
        $before = \count($workers);
        $started = [];
        $stopped = [];

        if ($target > $before) {
            $template = $workers[0] ?? throw new \RuntimeException('no running worker to clone');
            $next = max(array_map(static fn (array $w): int => $w['number'], $this->workers())) + 1;
            for ($i = $before; $i < $target; ++$i, ++$next) {
                $started[] = $this->clone($template['name'], $next);
            }
        } elseif ($target < $before) {
            // newest first; never below the template
            $extra = array_reverse(\array_slice($workers, $target));
            foreach ($extra as $w) {
                $this->run(['stop', '-t', '15', $w['name']]);
                $this->run(['rm', $w['name']]);
                $stopped[] = $w['name'];
            }
        }

        return ['target' => $target, 'before' => $before, 'after' => $before + \count($started) - \count($stopped), 'started' => $started, 'stopped' => $stopped, 'workers' => $this->workers()];
    }

    private function clone(string $templateName, int $number): string
    {
        $json = implode("\n", $this->run(['inspect', $templateName]));
        /** @var list<array<string, mixed>> $inspected */
        $inspected = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $t = $inspected[0] ?? throw new \RuntimeException("cannot inspect {$templateName}");
        /** @var array{Image: string, Env: list<string>, Cmd: list<string>|null, Labels: array<string, string>} $config */
        $config = $t['Config'];
        /** @var list<array{Type: string, Source?: string, Name?: string, Destination: string, RW?: bool}> $mounts */
        $mounts = $t['Mounts'] ?? [];
        /** @var array<string, array<string, mixed>> $networks */
        $networks = $t['NetworkSettings']['Networks'] ?? [];

        $name = "{$this->project}-{$this->service}-{$number}";
        $args = ['run', '-d', '--name', $name, '--restart', 'unless-stopped', '--stop-timeout', '15', '--no-healthcheck'];
        foreach (array_keys($networks) as $net) {
            $args[] = '--network';
            $args[] = $net;
        }
        foreach ($config['Env'] as $env) {
            if (str_starts_with($env, 'HOSTNAME=')) {
                continue; // docker sets a fresh one; the worker uses it as its consumer name
            }
            $args[] = '-e';
            $args[] = $env;
        }
        foreach ($mounts as $m) {
            $source = 'bind' === $m['Type'] ? ($m['Source'] ?? '') : ($m['Name'] ?? '');
            if ('' === $source) {
                continue;
            }
            $args[] = '-v';
            $args[] = $source.':'.$m['Destination'].(($m['RW'] ?? true) ? '' : ':ro');
        }
        foreach ($config['Labels'] as $k => $v) {
            if (!str_starts_with($k, 'com.docker.compose.')) {
                continue;
            }
            if ('com.docker.compose.container-number' === $k) {
                $v = (string) $number;
            }
            $args[] = '--label';
            $args[] = "{$k}={$v}";
        }
        $args[] = $config['Image'];
        foreach ($config['Cmd'] ?? [] as $c) {
            $args[] = $c;
        }
        $this->run($args);

        return $name;
    }

    /**
     * @param list<string> $args
     *
     * @return list<string> stdout lines
     */
    private function run(array $args): array
    {
        // A wedged daemon must not pin the scaler lock forever; 60 s covers a slow `docker run` on a cold image.
        $cmd = 'timeout 60 docker '.implode(' ', array_map(escapeshellarg(...), $args)).' 2>&1';
        exec($cmd, $output, $code);
        if (0 !== $code) {
            throw new \RuntimeException(sprintf('docker %s failed (%d): %s', $args[0], $code, implode(' ', $output)));
        }

        return array_values(array_filter(array_map(trim(...), $output), static fn (string $l): bool => '' !== $l));
    }
}
