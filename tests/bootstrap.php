<?php

// ---------------------------------------------------------------------------
// Stubs for classes that live on Toolforge but are not available locally.
// These are the minimal interfaces needed by WikiStream; they are never
// exercised directly in unit tests – individual test cases replace them with
// PHPUnit mocks or hand-rolled fakes.
// ---------------------------------------------------------------------------

if (!class_exists('ToolforgeCommon')) {
    class ToolforgeCommon
    {
        public function __construct(string $toolkey = '') {}
        public function openDBtool(string $db): object { return new class {}; }
        public function openDBwiki(string $wiki): object { return new class {}; }
        public function getSQL(object $db, string $sql): object
        {
            return new class {
                public function fetch_object(): false { return false; }
            };
        }
        public function getSPARQL_TSV(string $sparql): array { return []; }
        public function parseItemFromURL(string $url): string { return ''; }
        public function getCurrentTimestamp(): string { return date('YmdHis'); }
        public function getWikiPageText(string $wiki, string $page): string { return ''; }
        public function getRequest(string $key, mixed $default = null): mixed { return $default; }
        public function getQS(string $tool, string $ini): object { return new class {}; }
        public function runCommandsQS(array $cmds, object $qs): void {}
    }
}

if (!class_exists('WikidataItem')) {
    class WikidataItem
    {
        public object $j;

        public function __construct(object $j)
        {
            $this->j = $j;
        }

        public function getQ(): string { return $this->j->id ?? ''; }
        public function getLabel(string $lang = 'en'): string { return ''; }
        public function getClaims(string|int $prop): array { return []; }
        public function getTarget(object $claim): string { return ''; }
        public function getFirstString(string $prop): string { return ''; }
        public function hasTarget(string $prop, string $target): bool { return false; }
        public function getSitelinks(): array { return []; }
    }
}

if (!class_exists('WikidataItemList')) {
    class WikidataItemList
    {
        /** @var array<string|int, WikidataItem> */
        private array $items = [];

        public function loadItems(array $qs): void {}

        public function getItem(string|int $q): ?WikidataItem { return $this->items[$q] ?? null; }

        /** Allow tests to pre-populate items without touching the network. */
        public function setItem(string|int $q, WikidataItem $item): void
        {
            $this->items[$q] = $item;
        }
    }
}

// ---------------------------------------------------------------------------
// Production source files.
// config.php declares the WikiStreamConfig* classes.
// wikistream.php declares WikiStream itself; it requires ToolforgeCommon and
// wikidata.php at the top, but those require_once calls are silently skipped
// because the classes are already defined above.
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../scripts/config.php';

// Prevent the require_once calls inside wikistream.php from trying to load
// files that don't exist in the local checkout by overriding the include path.
// The stubs above are already loaded, so the require_once calls will find the
// classes defined and do nothing.
set_include_path(get_include_path() . PATH_SEPARATOR . __DIR__);

// Create empty stub files in a temp location that map to the paths wikistream.php
// tries to include, so require_once won't emit warnings for missing files.
(function () {
    $stubDir = sys_get_temp_dir() . '/wikistream_test_stubs';
    $phpDir  = $stubDir . '/php';
    if (!is_dir($phpDir)) {
        mkdir($phpDir, 0777, true);
    }
    // wikistream.php does:
    //   require_once __DIR__ . "/../public_html/php/ToolforgeCommon.php";
    //   require_once __DIR__ . "/../public_html/php/wikidata.php";
    // We satisfy those by putting no-op files at the real paths.
    $publicPhpDir = __DIR__ . '/../public_html/php';
    if (!is_dir($publicPhpDir)) {
        mkdir($publicPhpDir, 0777, true);
    }
    foreach (['ToolforgeCommon.php', 'wikidata.php'] as $stub) {
        $path = $publicPhpDir . '/' . $stub;
        if (!file_exists($path)) {
            file_put_contents($path, "<?php // test stub – classes already defined in bootstrap\n");
        }
    }
})();

require_once __DIR__ . '/../scripts/wikistream.php';
