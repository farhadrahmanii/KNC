<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Must pass in GitHub Actions before a deploy, and again on the VPS
 * before nginx is pointed at the new release.
 */
class ReleaseGateCommand extends Command
{
    protected $signature = 'mina:release-gate
                            {--skip-compile : Skip view:cache (already ran optimize)}';

    protected $description = 'Fail if this tree is not safe to put on production (routes, views, boot)';

    public function handle(): int
    {
        $this->info('Release gate — this tree must boot, compile, and answer the public pages.');
        $this->newLine();

        $failed = false;

        if (! $this->assertApplicationBoots()) {
            $failed = true;
        }

        if (! $this->assertNamedRoutesExist()) {
            $failed = true;
        }

        if (! $this->assertViteManifest()) {
            $failed = true;
        }

        if (! $this->option('skip-compile') && ! $this->assertViewsCompile()) {
            $failed = true;
        }

        if (! $this->assertPublicPagesRender()) {
            $failed = true;
        }

        $this->newLine();

        if ($failed) {
            $this->error('Release gate FAILED. Do not deploy this commit.');
            if (getenv('GITHUB_ACTIONS')) {
                $this->line('::error title=Release gate failed::This commit is not production-ready. Named routes, the Vite build, Blade compile, or a public page failed. Production was not updated.');
            }

            return self::FAILURE;
        }

        $this->info('Release gate passed. This commit may be deployed.');

        return self::SUCCESS;
    }

    private function assertApplicationBoots(): bool
    {
        $this->line('1. Application boot + route list');

        try {
            $count = collect(Route::getRoutes())->count();
        } catch (Throwable $e) {
            $this->failCheck('Laravel could not load routes: '.$e->getMessage());

            return false;
        }

        if ($count < 1) {
            $this->failCheck('No HTTP routes were registered.');

            return false;
        }

        $this->ok("Loaded {$count} routes.");

        return true;
    }

    private function assertNamedRoutesExist(): bool
    {
        $this->line('2. Named routes used in app code must exist');

        $registered = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (is_string($name) && $name !== '') {
                $registered[$name] = true;
            }
        }

        $failed = false;

        foreach ($this->requiredRouteNames() as $name) {
            if (isset($registered[$name])) {
                continue;
            }

            $this->failCheck("Required route [{$name}] is not registered.");
            $this->githubError($name, ['routes/web.php']);
            $failed = true;
        }

        foreach ($this->referencedRouteNames() as $name => $files) {
            if (isset($registered[$name])) {
                continue;
            }

            $this->failCheck("Route [{$name}] is used but not registered. ".$this->fileList($files));
            $this->githubError($name, $files);
            $failed = true;
        }

        if (! $failed) {
            $this->ok('App code only calls registered route names.');
        }

        return ! $failed;
    }

    private function assertViteManifest(): bool
    {
        $this->line('3. Vite manifest (Filament admin theme)');

        $manifest = public_path('build/manifest.json');
        if (! is_file($manifest)) {
            $this->failCheck('public/build/manifest.json is missing. Run npm ci && npm run build. /admin will 500 without the compiled theme.');

            return false;
        }

        $this->ok('Vite manifest is present.');

        return true;
    }

    private function assertViewsCompile(): bool
    {
        $this->line('4. Compile Blade templates');

        $code = $this->callSilent('view:cache');
        if ($code !== self::SUCCESS) {
            $this->failCheck('php artisan view:cache failed. A Blade file has a compile error.');

            return false;
        }

        $this->ok('Blade templates compiled.');

        return true;
    }

    /**
     * CLI route:list cannot see a Filament or Vite 500. Dispatch the same
     * kernel nginx uses before traffic is switched.
     */
    private function assertPublicPagesRender(): bool
    {
        $this->line('5. HTTP kernel: /up, /, and /admin/login must not 500');

        $ok = true;
        $checks = [
            '/up' => [200],
            '/' => [301, 302, 303, 307, 308],
            '/admin/login' => [200, 301, 302, 303, 307, 308],
        ];

        foreach ($checks as $uri => $allowed) {
            if (! $this->assertUrl($uri, $allowed)) {
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * @param  list<int>  $allowed
     */
    private function assertUrl(string $uri, array $allowed): bool
    {
        try {
            $kernel = app(Kernel::class);
            $request = Request::create($uri, 'GET');
            $response = $kernel->handle($request);
            $status = $response->getStatusCode();
            $kernel->terminate($request, $response);
        } catch (Throwable $e) {
            $this->failCheck("GET {$uri} threw: ".$e->getMessage());

            return false;
        }

        if (! in_array($status, $allowed, true)) {
            $this->failCheck("GET {$uri} returned HTTP {$status}.");

            return false;
        }

        $this->ok("GET {$uri} returned HTTP {$status}.");

        return true;
    }

    /**
     * @return list<string>
     */
    private function requiredRouteNames(): array
    {
        return [
            'download.invoice',
            'download.soldDocs',
            'download.word',
            'filament.admin.auth.login',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function referencedRouteNames(): array
    {
        $pattern = '/(?:to_route|\broute)\(\s*[\'"]([a-zA-Z0-9_.\-]+)[\'"]/';
        $found = [];

        $roots = array_values(array_filter([
            base_path('app'),
            base_path('resources/views'),
            base_path('routes'),
        ], 'is_dir'));

        $finder = Finder::create()
            ->files()
            ->in($roots)
            ->name('*.php')
            ->exclude('vendor')
            ->notPath('vendor');

        foreach ($finder as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $content = $file->getContents();
            // $request->route('id') is a parameter, not a named route.
            $content = preg_replace('/\$(?:this|request)->route\s*\(/i', 'REQUEST_ROUTE(', $content) ?? $content;

            if (preg_match_all($pattern, $content, $matches) < 1) {
                continue;
            }

            foreach ($matches[1] as $name) {
                $found[$name] ??= [];
                if (! in_array($relative, $found[$name], true)) {
                    $found[$name][] = $relative;
                }
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $files
     */
    private function fileList(array $files): string
    {
        $shown = array_slice($files, 0, 5);
        $extra = count($files) > 5 ? ' (+'.(count($files) - 5).' more)' : '';

        return implode(', ', $shown).$extra;
    }

    /**
     * @param  list<string>  $files
     */
    private function githubError(string $name, array $files): void
    {
        if (! getenv('GITHUB_ACTIONS')) {
            return;
        }

        $this->line("::error title=Missing route {$name}::Referenced in ".$this->fileList($files));
    }

    private function ok(string $message): void
    {
        $this->line('   [OK] '.$message);
    }

    private function failCheck(string $message): void
    {
        $this->error('   [FAIL] '.$message);
    }
}
