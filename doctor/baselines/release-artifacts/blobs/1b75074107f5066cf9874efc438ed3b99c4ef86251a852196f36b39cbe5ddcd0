<?php

declare(strict_types=1);

namespace SysAdmin;

final class SitePageService
{
    public function pages(string $siteRoot): array
    {
        $root = $this->assertSiteRoot($siteRoot);
        $isLaravel = $this->isLaravelSite($root);
        $routesFile = $isLaravel ? $this->routesFile($root) : '';
        $viewsRoot = $isLaravel ? $this->viewsRoot($root) : $root;

        return [
            'site' => [
                'name' => basename($root),
                'path' => $root,
                'kind' => $isLaravel ? 'laravel' : 'static',
                'editor_label' => $isLaravel ? 'Blade' : 'File',
                'routes_file' => $routesFile,
                'views_root' => $viewsRoot,
                'url' => $this->urlForRoute($root, '/'),
            ],
            'pages' => $isLaravel ? $this->discoverEditableBladePages($root) : $this->discoverStaticPages($root),
            'can_create' => $isLaravel
                ? ($this->canSaveExistingTextFile($routesFile) && $this->canCreateBlade($viewsRoot . '/page.blade.php'))
                : $this->canCreateStaticPage($root . '/page.html'),
        ];
    }

    public function readBlade(string $siteRoot, string $view): array
    {
        $root = $this->assertSiteRoot($siteRoot);
        if (!$this->isLaravelSite($root)) {
            return $this->readStaticPage($root, $view);
        }

        $view = $this->normalizeViewName($view);
        $path = $this->bladePath($root, $view);
        $exists = is_file($path);
        $content = $exists
            ? (string) ((new FileVault(true))->read($path)['content'] ?? '')
            : $this->defaultBladeContent('/', $view);

        return [
            'view' => $view,
            'kind' => 'laravel',
            'editor_label' => 'Blade',
            'path' => $path,
            'exists' => $exists,
            'writable' => $exists ? $this->canSaveExistingTextFile($path) : $this->canCreateBlade($path),
            'content' => $content,
        ];
    }

    public function saveBlade(string $siteRoot, string $view, string $content): array
    {
        $root = $this->assertSiteRoot($siteRoot);
        if (!$this->isLaravelSite($root)) {
            return $this->saveStaticPage($root, $view, $content);
        }

        $view = $this->normalizeViewName($view);
        $path = $this->bladePath($root, $view);
        $this->assertEditableContentSize($content);

        if (is_file($path)) {
            (new FileVault(true))->saveFile($path, $content);
        } else {
            $this->writeNewTextFile($path, $content);
        }

        AuditLogger::info('hosting.page.blade.update', [
            'site' => $root,
            'view' => $view,
            'path' => $path,
        ]);

        return $this->readBlade($root, $view);
    }

    public function createPage(string $siteRoot, string $uri, string $view, string $content = ''): array
    {
        $root = $this->assertSiteRoot($siteRoot);
        if (!$this->isLaravelSite($root)) {
            return $this->createStaticPage($root, $uri, $content);
        }

        $uri = $this->normalizeRouteUri($uri, true);
        $view = $this->normalizeViewName($view);
        $routesFile = $this->routesFile($root);
        $bladePath = $this->bladePath($root, $view);
        $this->assertEditableContentSize($content);

        $routesSource = $this->readTextFile($routesFile);
        foreach ($this->routeSources($root) as $routeSource) {
            if ($this->routeUriExists($routeSource['source'], $uri)) {
                throw new \InvalidArgumentException('A route already exists for that URL path.');
            }
        }
        if (is_file($bladePath)) {
            throw new \InvalidArgumentException('A Blade view already exists with that name.');
        }

        $bladeContent = trim($content) !== '' ? $content : $this->defaultBladeContent($uri, $view);
        $this->writeNewTextFile($bladePath, $bladeContent);

        $routeLine = "Route::view('" . addslashes($uri) . "', '" . addslashes($view) . "');";
        $updatedRoutes = rtrim($routesSource) . PHP_EOL . PHP_EOL . $routeLine . PHP_EOL;
        (new FileVault(true))->saveFile($routesFile, $updatedRoutes);

        AuditLogger::info('hosting.page.create', [
            'site' => $root,
            'uri' => $uri,
            'view' => $view,
            'blade' => $bladePath,
        ]);

        return $this->pages($root);
    }

    public function deletePage(string $siteRoot, string $uri, string $view, bool $deleteBlade = false): array
    {
        $root = $this->assertSiteRoot($siteRoot);
        if (!$this->isLaravelSite($root)) {
            return $this->deleteStaticPage($root, $view !== '' ? $view : $uri);
        }

        $uri = $this->normalizeRouteUri($uri, false);
        $view = $this->normalizeViewName($view);
        $pages = $this->discoverPages($root, true);
        $target = null;

        foreach ($pages as $page) {
            if ($page['uri'] === $uri && $page['view'] === $view) {
                $target = $page;
                break;
            }
        }
        if (!$target) {
            throw new \InvalidArgumentException('Route-backed Blade page was not found.');
        }

        $routesFile = (string) $target['_route_file'];
        $routesSource = $this->readTextFile($routesFile);
        $start = (int) $target['_statement_start'];
        $length = (int) $target['_statement_length'];
        $end = $start + $length;
        while ($start > 0 && ($routesSource[$start - 1] ?? '') === "\n") {
            $start--;
        }
        while ($end < strlen($routesSource) && in_array($routesSource[$end], ["\r", "\n"], true)) {
            $end++;
        }

        $updatedRoutes = substr($routesSource, 0, $start) . substr($routesSource, $end);
        (new FileVault(true))->saveFile($routesFile, $updatedRoutes);

        $trash = '';
        if ($deleteBlade) {
            $bladePath = $this->bladePath($root, $view);
            if (is_file($bladePath)) {
                $trash = (new FileVault(true))->delete($bladePath);
            }
        }

        AuditLogger::warning('hosting.page.delete', [
            'site' => $root,
            'uri' => $uri,
            'view' => $view,
            'deleted_blade' => $trash !== '',
            'trash' => $trash,
        ]);

        return $this->pages($root);
    }

    private function routeSources(string $root): array
    {
        $webFile = $this->routesFile($root);
        $sources = [
            $webFile => [
                'file' => $webFile,
                'source' => $this->readTextFile($webFile),
            ],
        ];

        preg_match_all("~require\\s+__DIR__\\s*\\.\\s*(['\"])([^'\"]+)\\1\\s*;~", $sources[$webFile]['source'], $matches);
        foreach ($matches[2] ?? [] as $relative) {
            $candidate = realpath(dirname($webFile) . DIRECTORY_SEPARATOR . ltrim((string) $relative, DIRECTORY_SEPARATOR));
            $routesRoot = realpath(dirname($webFile));
            if (!$candidate || !$routesRoot || !str_starts_with($candidate, $routesRoot . DIRECTORY_SEPARATOR) || !is_file($candidate)) {
                continue;
            }

            $sources[$candidate] = [
                'file' => $candidate,
                'source' => $this->readTextFile($candidate),
            ];
        }

        return array_values($sources);
    }

    private function discoverStaticPages(string $root): array
    {
        $pages = [];
        $this->collectStaticPages($root, $root, $pages, 0);
        usort($pages, static fn (array $a, array $b): int => [$a['uri'], $a['view']] <=> [$b['uri'], $b['view']]);

        return $pages;
    }

    private function collectStaticPages(string $root, string $directory, array &$pages, int $depth): void
    {
        if ($depth > 5 || count($pages) >= 400 || !is_readable($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                if (in_array($entry, ['vendor', 'node_modules', 'storage', 'cache'], true)) {
                    continue;
                }
                $this->collectStaticPages($root, $path, $pages, $depth + 1);
                continue;
            }

            if (is_file($path) && $this->isStaticPageFile($path)) {
                $pages[] = $this->staticPagePayload($root, $path);
            }
        }
    }

    private function staticPagePayload(string $root, string $path): array
    {
        $relative = $this->relativeStaticPath($root, $path);
        $uri = $this->uriForStaticRelativePath($relative);

        return [
            'kind' => 'static',
            'method' => 'GET',
            'uri' => $uri,
            'url' => $this->urlForRoute($root, $uri),
            'name' => '',
            'view' => $relative,
            'label' => $relative,
            'editor_label' => 'File',
            'blade_path' => $path,
            'blade_exists' => is_file($path),
            'blade_readable' => is_file($path) && is_readable($path),
            'blade_writable' => is_file($path) ? $this->canSaveExistingTextFile($path) : $this->canCreateStaticPage($path),
            'route_type' => 'file',
            'route_file' => '',
            'route_writable' => false,
            'controller' => '',
            'controller_action' => '',
            'controller_path' => '',
        ];
    }

    private function readStaticPage(string $root, string $identifier): array
    {
        $path = $this->staticPagePath($root, $identifier);
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Selected page file was not found.');
        }

        $relative = $this->relativeStaticPath($root, $path);

        return [
            'view' => $relative,
            'kind' => 'static',
            'editor_label' => 'File',
            'path' => $path,
            'exists' => true,
            'writable' => $this->canSaveExistingTextFile($path),
            'content' => (string) ((new FileVault(true))->read($path)['content'] ?? ''),
        ];
    }

    private function saveStaticPage(string $root, string $identifier, string $content): array
    {
        $path = $this->staticPagePath($root, $identifier);
        $this->assertEditableContentSize($content);

        if (is_file($path)) {
            (new FileVault(true))->saveFile($path, $content);
        } else {
            $this->writeNewTextFile($path, $content);
        }

        AuditLogger::info('hosting.page.file.update', [
            'site' => $root,
            'path' => $path,
        ]);

        return $this->readStaticPage($root, $this->relativeStaticPath($root, $path));
    }

    private function createStaticPage(string $root, string $uri, string $content = ''): array
    {
        $relative = $this->staticRelativePathFromUri($uri);
        $path = $this->staticPagePath($root, $relative);
        if (file_exists($path)) {
            throw new \InvalidArgumentException('A page file already exists for that URL path.');
        }

        $pageContent = trim($content) !== '' ? $content : $this->defaultStaticPageContent($uri);
        $this->writeNewTextFile($path, $pageContent);

        AuditLogger::info('hosting.page.file.create', [
            'site' => $root,
            'uri' => $this->uriForStaticRelativePath($relative),
            'path' => $path,
        ]);

        return $this->pages($root);
    }

    private function deleteStaticPage(string $root, string $identifier): array
    {
        $path = $this->staticPagePath($root, $identifier);
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Selected page file was not found.');
        }

        $trash = (new FileVault(true))->delete($path);
        AuditLogger::warning('hosting.page.file.delete', [
            'site' => $root,
            'path' => $path,
            'trash' => $trash,
        ]);

        return $this->pages($root);
    }

    private function discoverPages(string $root, bool $includeInternal): array
    {
        $pages = [];

        foreach ($this->routeSources($root) as $routeSource) {
            $routesSource = $routeSource['source'];
            $routeFile = $routeSource['file'];
            $imports = $this->controllerImports($routesSource);

            $this->collectRouteViewPages($root, $routesSource, $routeFile, $pages);
            $this->collectClosureViewPages($root, $routesSource, $routeFile, $pages);
            $this->collectArrowViewPages($root, $routesSource, $routeFile, $pages);
            $this->collectControllerViewPages($root, $routesSource, $routeFile, $imports, $pages);
        }

        usort($pages, static fn (array $a, array $b): int => [$a['uri'], $a['view']] <=> [$b['uri'], $b['view']]);

        if ($includeInternal) {
            return $pages;
        }

        return array_map(static function (array $page): array {
            unset($page['_statement_start'], $page['_statement_length'], $page['_statement']);
            return $page;
        }, $pages);
    }

    private function discoverEditableBladePages(string $root): array
    {
        $pages = $this->discoverPages($root, false);
        $seenViews = [];

        foreach ($pages as $page) {
            $seenViews[(string) $page['view']] = true;
        }

        foreach ($this->discoverBladeFiles($root) as $blade) {
            if (isset($seenViews[$blade['view']])) {
                continue;
            }

            $pages[] = [
                'kind' => 'laravel',
                'method' => '',
                'uri' => 'Blade file',
                'url' => '',
                'name' => '',
                'view' => $blade['view'],
                'label' => $blade['view'],
                'editor_label' => 'Blade',
                'blade_path' => $blade['path'],
                'blade_exists' => true,
                'blade_readable' => is_readable($blade['path']),
                'blade_writable' => $this->canSaveExistingTextFile($blade['path']),
                'route_type' => 'blade-file',
                'route_file' => '',
                'route_writable' => false,
                'controller' => '',
                'controller_action' => '',
                'controller_path' => '',
                'deletable' => false,
            ];
        }

        usort($pages, static function (array $a, array $b): int {
            $aBladeOnly = ((string) ($a['route_type'] ?? '')) === 'blade-file' ? 1 : 0;
            $bBladeOnly = ((string) ($b['route_type'] ?? '')) === 'blade-file' ? 1 : 0;
            return [$aBladeOnly, (string) $a['uri'], (string) $a['view']] <=> [$bBladeOnly, (string) $b['uri'], (string) $b['view']];
        });

        return $pages;
    }

    private function discoverBladeFiles(string $root): array
    {
        $viewsRoot = PathGuard::assertAllowedDirectory($this->viewsRoot($root));
        $files = [];
        $this->collectBladeFiles($viewsRoot, $viewsRoot, $files, 0);

        usort($files, static fn (array $a, array $b): int => $a['view'] <=> $b['view']);

        return $files;
    }

    private function collectBladeFiles(string $viewsRoot, string $directory, array &$files, int $depth): void
    {
        if ($depth > 12 || count($files) >= 1200 || !is_readable($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->collectBladeFiles($viewsRoot, $path, $files, $depth + 1);
                continue;
            }

            if (!is_file($path) || !str_ends_with($entry, '.blade.php')) {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($viewsRoot) + 1));
            $view = substr($relative, 0, -strlen('.blade.php'));
            $view = str_replace('/', '.', $view);
            if (!$this->isLocalViewName($view)) {
                continue;
            }

            $files[] = [
                'view' => $view,
                'path' => $path,
            ];
        }
    }

    private function collectRouteViewPages(string $root, string $routesSource, string $routeFile, array &$pages): void
    {
        preg_match_all(
            "~Route::view\\s*\\(\\s*(['\"])(?P<uri>.*?)\\1\\s*,\\s*(['\"])(?P<view>.*?)\\3(?:\\s*,.*?)?\\)\\s*(?P<chain>(?:->\\w+\\s*\\([^;]*\\)\\s*)*)\\s*;~s",
            $routesSource,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        foreach ($matches as $match) {
            $this->addPage($root, $pages, $match, $routeFile, $match['uri'][0], $match['view'][0], 'route-view');
        }
    }

    private function collectClosureViewPages(string $root, string $routesSource, string $routeFile, array &$pages): void
    {
        preg_match_all(
            "~Route::get\\s*\\(\\s*(['\"])(?P<uri>.*?)\\1\\s*,\\s*function\\s*\\([^)]*\\)\\s*(?:use\\s*\\([^)]*\\)\\s*)?\\{(?P<body>.*?)\\}\\s*\\)\\s*(?P<chain>(?:->\\w+\\s*\\([^;]*\\)\\s*)*)\\s*;~s",
            $routesSource,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        foreach ($matches as $match) {
            $view = $this->firstViewName((string) $match['body'][0]);
            if ($view !== null) {
                $this->addPage($root, $pages, $match, $routeFile, $match['uri'][0], $view, 'closure');
            }
        }
    }

    private function collectArrowViewPages(string $root, string $routesSource, string $routeFile, array &$pages): void
    {
        preg_match_all(
            "~Route::get\\s*\\(\\s*(['\"])(?P<uri>.*?)\\1\\s*,\\s*fn\\s*\\([^)]*\\)\\s*=>\\s*(?P<body>.*?)\\)\\s*(?P<chain>(?:->\\w+\\s*\\([^;]*\\)\\s*)*)\\s*;~s",
            $routesSource,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        foreach ($matches as $match) {
            $view = $this->firstViewName((string) $match['body'][0]);
            if ($view !== null) {
                $this->addPage($root, $pages, $match, $routeFile, $match['uri'][0], $view, 'closure');
            }
        }
    }

    private function collectControllerViewPages(string $root, string $routesSource, string $routeFile, array $imports, array &$pages): void
    {
        preg_match_all(
            "~Route::get\\s*\\(\\s*(['\"])(?P<uri>.*?)\\1\\s*,\\s*\\[\\s*(?P<controller>[A-Za-z_][A-Za-z0-9_\\\\]*)::class\\s*,\\s*(['\"])(?P<action>[A-Za-z_][A-Za-z0-9_]*)\\4\\s*\\]\\s*\\)\\s*(?P<chain>(?:->\\w+\\s*\\([^;]*\\)\\s*)*)\\s*;~s",
            $routesSource,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE
        );

        foreach ($matches as $match) {
            $controller = (string) $match['controller'][0];
            $action = (string) $match['action'][0];
            $controllerInfo = $this->controllerView($root, $controller, $action, $imports);
            if (!$controllerInfo) {
                continue;
            }

            $this->addPage(
                $root,
                $pages,
                $match,
                $routeFile,
                $match['uri'][0],
                $controllerInfo['view'],
                'controller',
                $controllerInfo['class'],
                $action,
                $controllerInfo['path']
            );
        }
    }

    private function addPage(
        string $root,
        array &$pages,
        array $match,
        string $routeFile,
        string $uri,
        string $view,
        string $routeType,
        string $controller = '',
        string $action = '',
        string $controllerPath = ''
    ): void {
        if (!$this->isLocalViewName($view)) {
            return;
        }

        try {
            $uri = $this->normalizeRouteUri($uri, false);
            $view = $this->normalizeViewName($view);
        } catch (\InvalidArgumentException) {
            return;
        }
        $bladePath = $this->bladePath($root, $view);
        $statement = (string) $match[0][0];

        $pages[] = [
            'method' => 'GET',
            'uri' => $uri,
            'url' => $this->urlForRoute($root, $uri),
            'name' => $this->routeName($statement),
            'view' => $view,
            'blade_path' => $bladePath,
            'blade_exists' => is_file($bladePath),
            'blade_readable' => is_file($bladePath) && is_readable($bladePath),
            'blade_writable' => is_file($bladePath) ? $this->canSaveExistingTextFile($bladePath) : $this->canCreateBlade($bladePath),
            'route_type' => $routeType,
            'route_file' => $routeFile,
            'route_writable' => $this->canSaveExistingTextFile($routeFile),
            'controller' => $controller,
            'controller_action' => $action,
            'controller_path' => $controllerPath,
            '_statement_start' => (int) $match[0][1],
            '_statement_length' => strlen($statement),
            '_statement' => $statement,
            '_route_file' => $routeFile,
        ];
    }

    private function controllerImports(string $routesSource): array
    {
        preg_match_all('/^use\\s+([^;]+);/m', $routesSource, $matches);
        $imports = [];
        foreach ($matches[1] ?? [] as $import) {
            $import = trim($import);
            if (!str_starts_with($import, 'App\\Http\\Controllers\\')) {
                continue;
            }

            $alias = '';
            if (preg_match('/\\s+as\\s+([A-Za-z_][A-Za-z0-9_]*)$/i', $import, $aliasMatch)) {
                $alias = $aliasMatch[1];
                $import = trim(preg_replace('/\\s+as\\s+[A-Za-z_][A-Za-z0-9_]*$/i', '', $import) ?? $import);
            }
            if ($alias === '') {
                $parts = explode('\\', $import);
                $alias = end($parts) ?: $import;
            }

            $imports[$alias] = $import;
        }

        return $imports;
    }

    private function controllerView(string $root, string $controller, string $action, array $imports): ?array
    {
        $class = $this->resolveControllerClass($controller, $imports);
        $path = $this->controllerPath($root, $class);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $source = file_get_contents($path);
        if ($source === false) {
            return null;
        }

        $body = $this->methodBody($source, $action);
        if ($body === null) {
            return null;
        }

        $view = $this->firstViewName($body);
        if ($view === null) {
            $view = $this->delegatedViewName($source, $body);
        }
        if ($view === null) {
            return null;
        }

        return ['class' => $class, 'path' => $path, 'view' => $view];
    }

    private function resolveControllerClass(string $controller, array $imports): string
    {
        if (str_contains($controller, '\\')) {
            return str_starts_with($controller, 'App\\') ? $controller : 'App\\Http\\Controllers\\' . ltrim($controller, '\\');
        }

        return $imports[$controller] ?? 'App\\Http\\Controllers\\' . $controller;
    }

    private function controllerPath(string $root, string $class): string
    {
        if (!str_starts_with($class, 'App\\')) {
            return '';
        }

        return $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }

    private function methodBody(string $source, string $method): ?string
    {
        if (!preg_match('/function\\s+' . preg_quote($method, '/') . '\\s*\\([^)]*\\)\\s*(?::\\s*[^\\{]+)?\\{/m', $source, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $start = (int) $match[0][1] + strlen((string) $match[0][0]);
        $depth = 1;
        $length = strlen($source);
        for ($i = $start; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }

        return null;
    }

    private function delegatedViewName(string $source, string $body): ?string
    {
        if (!preg_match('/return\\s+\\$this->([A-Za-z_][A-Za-z0-9_]*)\\s*\\(/', $body, $match)) {
            return null;
        }

        $helperBody = $this->methodBody($source, $match[1]);
        return $helperBody !== null ? $this->firstViewName($helperBody) : null;
    }

    private function firstViewName(string $source): ?string
    {
        if (!preg_match('~\\bview\\s*\\(\\s*([\'"])([^\'"]+)\\1~', $source, $match)) {
            return null;
        }

        return $this->isLocalViewName($match[2]) ? $match[2] : null;
    }

    private function routeName(string $statement): string
    {
        if (!preg_match('~->name\\s*\\(\\s*([\'"])([^\'"]+)\\1\\s*\\)~', $statement, $match)) {
            return '';
        }

        return (string) $match[2];
    }

    private function routeUriExists(string $routesSource, string $uri): bool
    {
        return preg_match("~Route::(?:get|view|post|put|patch|delete|match|any)\\s*\\(\\s*(['\"])" . preg_quote($uri, '~') . "\\1~", $routesSource) === 1;
    }

    private function assertSiteRoot(string $siteRoot): string
    {
        $root = PathGuard::assertAllowedDirectory($siteRoot);
        if (is_link($root) || str_starts_with(basename($root), '.')) {
            throw new \InvalidArgumentException('Selected site cannot be managed from this page.');
        }

        return $root;
    }

    private function isLaravelSite(string $root): bool
    {
        return is_file($this->routesFile($root)) && is_dir($this->viewsRoot($root));
    }

    private function assertLaravelSite(string $siteRoot): string
    {
        $root = $this->assertSiteRoot($siteRoot);
        if (!$this->isLaravelSite($root)) {
            throw new \InvalidArgumentException('Selected site does not have Laravel routes and Blade views.');
        }

        return $root;
    }

    private function routesFile(string $root): string
    {
        return $root . '/routes/web.php';
    }

    private function viewsRoot(string $root): string
    {
        return $root . '/resources/views';
    }

    private function bladePath(string $root, string $view): string
    {
        $relative = str_replace('.', DIRECTORY_SEPARATOR, $this->normalizeViewName($view)) . '.blade.php';
        $viewsRoot = PathGuard::assertAllowedDirectory($this->viewsRoot($root));
        $path = $viewsRoot . DIRECTORY_SEPARATOR . $relative;
        if (!str_starts_with($path, $viewsRoot . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Blade path is outside the site views folder.');
        }
        if (file_exists($path)) {
            PathGuard::assertAllowed($path);
        }

        return $path;
    }

    private function normalizeViewName(string $view): string
    {
        $view = trim(str_replace('/', '.', $view));
        $view = trim($view, '.');
        if (!$this->isLocalViewName($view)) {
            throw new \InvalidArgumentException('Use a local Blade view name like welcome or pages.about.');
        }

        return $view;
    }

    private function isLocalViewName(string $view): bool
    {
        return $view !== ''
            && !str_contains($view, '::')
            && preg_match('/\\A[A-Za-z0-9_.-]+\\z/', $view) === 1
            && !str_contains($view, '..');
    }

    private function staticPagePath(string $root, string $identifier): string
    {
        $root = PathGuard::assertAllowedDirectory($root);
        $relative = $this->normalizeStaticPageIdentifier($identifier);
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Page file is outside the selected site folder.');
        }
        if (file_exists($path)) {
            PathGuard::assertAllowed($path);
        }

        return $path;
    }

    private function normalizeStaticPageIdentifier(string $identifier): string
    {
        $relative = trim(str_replace('\\', '/', $identifier));
        $relative = ltrim($relative, '/');
        if ($relative === '') {
            $relative = 'index.html';
        }

        $parts = array_filter(explode('/', $relative), static fn (string $part): bool => $part !== '');
        if (!$parts || in_array('..', $parts, true)) {
            throw new \InvalidArgumentException('Use a valid page file path.');
        }
        foreach ($parts as $part) {
            if ($part === '.' || str_starts_with($part, '.')) {
                throw new \InvalidArgumentException('Hidden page paths cannot be edited here.');
            }
        }

        $relative = implode('/', $parts);
        if (!$this->isStaticPageFile($relative)) {
            throw new \InvalidArgumentException('Only .html, .htm, and .php page files are editable here.');
        }

        return $relative;
    }

    private function staticRelativePathFromUri(string $uri): string
    {
        $uri = $this->normalizeRouteUri($uri, true);
        $relative = trim($uri, '/');
        if ($relative === '') {
            return 'index.html';
        }
        if (str_ends_with($uri, '/')) {
            return $this->normalizeStaticPageIdentifier($relative . '/index.html');
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (in_array($extension, ['html', 'htm', 'php'], true)) {
            return $this->normalizeStaticPageIdentifier($relative);
        }

        return $this->normalizeStaticPageIdentifier($relative . '.html');
    }

    private function relativeStaticPath(string $root, string $path): string
    {
        $root = rtrim(PathGuard::assertAllowedDirectory($root), DIRECTORY_SEPARATOR);
        $real = realpath($path) ?: $path;
        if (!str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Page file is outside the selected site folder.');
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($real, strlen($root) + 1));
    }

    private function uriForStaticRelativePath(string $relative): string
    {
        $relative = $this->normalizeStaticPageIdentifier($relative);
        $basename = basename($relative);
        $directory = trim(dirname($relative), '.');

        if (in_array($basename, ['index.html', 'index.htm', 'index.php'], true)) {
            $directory = trim($directory, '/');
            return $directory === '' ? '/' : '/' . $directory . '/';
        }

        return '/' . $relative;
    }

    private function isStaticPageFile(string $path): bool
    {
        return preg_match('/\\.(?:html?|php)\\z/i', $path) === 1;
    }

    private function canCreateStaticPage(string $path): bool
    {
        return $this->canCreateTextFile($path);
    }

    private function normalizeRouteUri(string $uri, bool $simpleOnly): string
    {
        $uri = '/' . ltrim(trim($uri), '/');
        if ($uri !== '/' && str_ends_with($uri, '/')) {
            $uri = rtrim($uri, '/');
        }

        $pattern = $simpleOnly ? '~\A/[A-Za-z0-9/_.-]*\z~' : '~\A/[^\r\n\'"]*\z~';
        if (preg_match($pattern, $uri) !== 1 || str_contains($uri, '//')) {
            throw new \InvalidArgumentException('Use a valid route path starting with /.');
        }

        return $uri;
    }

    private function canCreateBlade(string $path): bool
    {
        return $this->canCreateTextFile($path);
    }

    private function canSaveExistingTextFile(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        return (is_readable($path) && is_writable($path)) || (new FileVault(true))->canUsePrivilegedHelper();
    }

    private function canCreateTextFile(string $path): bool
    {
        $parent = dirname($path);
        while (!is_dir($parent) && $parent !== dirname($parent)) {
            $parent = dirname($parent);
        }

        return is_dir($parent) && (is_writable($parent) || (new FileVault(true))->canUsePrivilegedHelper());
    }

    private function ensureEditableDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            PathGuard::assertAllowedDirectory($directory);
            return;
        }

        $parent = dirname($directory);
        if ($parent === $directory) {
            throw new \RuntimeException('Unable to create page folder.');
        }

        $this->ensureEditableDirectory($parent);
        if (@mkdir($directory, 0775) || is_dir($directory)) {
            @chmod($directory, 0775);
            return;
        }

        (new FileVault(true))->createFolder($parent, basename($directory));
    }

    private function writeNewTextFile(string $path, string $content): void
    {
        $this->assertEditableContentSize($content);
        $directory = dirname($path);
        $this->ensureEditableDirectory($directory);
        PathGuard::assertAllowedDirectory($directory);
        if (file_exists($path)) {
            throw new \RuntimeException('File already exists.');
        }
        if (@file_put_contents($path, $content, LOCK_EX) !== false) {
            @chmod($path, 0644);
            return;
        }

        (new FileVault(true))->createFile($directory, basename($path), $content);
    }

    private function readTextFile(string $path): string
    {
        PathGuard::assertAllowed($path);
        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Unable to read file.');
        }
        if ($content !== '' && preg_match('//u', $content) !== 1) {
            throw new \RuntimeException('File is not valid UTF-8 text.');
        }

        return $content;
    }

    private function assertEditableContentSize(string $content): void
    {
        $maxBytes = (int) config_value('security.max_file_edit_bytes', 1048576);
        if (strlen($content) > $maxBytes) {
            throw new \RuntimeException('Content is too large to edit in the browser.');
        }
    }

    private function defaultBladeContent(string $uri, string $view): string
    {
        $title = ucwords(str_replace(['.', '-', '_'], ' ', trim($view, '.')));
        if ($title === '') {
            $title = trim($uri, '/') !== '' ? ucwords(str_replace(['-', '_', '/'], ' ', trim($uri, '/'))) : 'Home';
        }

        return <<<BLADE
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title}</title>
</head>
<body>
    <main>
        <h1>{$title}</h1>
    </main>
</body>
</html>
BLADE;
    }

    private function defaultStaticPageContent(string $uri): string
    {
        $title = trim($uri, '/') !== '' ? ucwords(str_replace(['-', '_', '/', '.'], ' ', trim($uri, '/'))) : 'Home';

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$title}</title>
</head>
<body>
    <main>
        <h1>{$title}</h1>
    </main>
</body>
</html>
HTML;
    }

    private function urlForRoute(string $root, string $uri): string
    {
        $htdocs = realpath(EnvironmentConfig::paths('document_roots')[0] ?? dirname(APP_ROOT));
        $real = realpath($root);
        if (!$htdocs || !$real || !($real === $htdocs || str_starts_with($real, $htdocs . DIRECTORY_SEPARATOR))) {
            return '';
        }

        $relative = trim(substr($real, strlen($htdocs)), DIRECTORY_SEPARATOR);
        $base = $relative === '' ? 'http://localhost' : 'http://localhost/' . str_replace('%2F', '/', rawurlencode(str_replace(DIRECTORY_SEPARATOR, '/', $relative)));

        return rtrim($base, '/') . ($uri === '/' ? '/' : $uri);
    }
}
