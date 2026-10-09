<?php
/**
 * cache snippets|html|docs|templates|include|instances|all [--prefix=NAME | --remove=NAME] | list
 *
 * snippet cache var/cache/lang (SnippetCache) and HTML template cache var/cache/html
 * (HtmlCache) - the same as Tools > Developer in the backend, without session. Prefix of the
 * core: 'core' (snippets) or '' (HTML, --prefix=core also works).
 * docs: search and keyword index of the manuals (Modules\Docs\Docs::rebuildIndex()).
 * templates: like Tools > Developer > Templates - recompile all templates
 * (Template::rebuildFiles()) and compare var/templates (TemplateFiles::sync()) - after a
 * change to the compiler; also rewrites the parser messages (error_text).
 * include: clear the output cache of CMS:INCLUDE (IncludeCache::clear(), var/cache/include);
 * --template={area_id}/{name} only for one template. Otherwise it is cleaned up by the
 * scheduler task cachecleanup.
 * instances: file cache of the instances, domains, languages, areas and group permissions
 * (InstanceCache::rebuild(), var/cache/instances.php, instance-*.php, group-*.php) -
 * after a change to its structure; otherwise the hooks of the modules write it.
 * snippets first converts snippet sets of the instances in the earlier PHP format
 * (var/custom/snippets/{id}/lang/*.php) into .ini files (Snippets::convertLegacy()).
 */
namespace Camindo\Core\Cli;

use Camindo\Core\HtmlCache;
use Camindo\Core\IncludeCache;
use Camindo\Core\InstanceCache;
use Camindo\Core\SnippetCache;
use Camindo\Core\Modules\Docs\Docs;
use Camindo\Core\Modules\Snippets\Snippets;
use Camindo\Core\Modules\Template\Template;
use Camindo\Core\Modules\Template\TemplateFiles;

class CacheCommand extends Command {

    public function run(array $args, array $options): int
    {
        $what = $args[0] ?? '';
        if ($what === 'list') {
            $this->out('snippets: ' . implode(', ', $this->get(SnippetCache::class)->prefixes()));
            $this->out('html:     ' . implode(', ', array_map(fn($p) => $p === '' ? 'core' : $p, $this->get(HtmlCache::class)->prefixes())));
            return Cli::OK;
        }
        if (!in_array($what, ['snippets', 'html', 'docs', 'templates', 'include', 'instances', 'all'], true)) {
            return $this->usage('usage: cache snippets|html|docs|templates|include|instances|all [--prefix=NAME | --remove=NAME] | list');
        }
        $remove = is_string($options['remove'] ?? null) ? $options['remove'] : null;
        $prefix = is_string($options['prefix'] ?? null) ? $options['prefix'] : null;

        if ($what === 'instances' || ($what === 'all' && $prefix === null && $remove === null)) {
            $this->get(InstanceCache::class)->rebuild();
            $this->out('instances: instance and group files written');
            if ($what === 'instances') return Cli::OK;
        }

        if ($what === 'include' || ($what === 'all' && $prefix === null && $remove === null)) {
            $template = is_string($options['template'] ?? null) ? $options['template'] : '';
            $this->out('include: ' . $this->get(IncludeCache::class)->clear($template) . ' files deleted'
                . ($template !== '' ? " for '$template'" : ''));
            if ($what === 'include') return Cli::OK;
        }

        if ($what === 'docs' || ($what === 'all' && $prefix === null && $remove === null)) {
            foreach ($this->get(Docs::class)->rebuildIndex() as $lang => $r) {
                $this->out("docs $lang: {$r['pages']} pages, {$r['sections']} sections, {$r['keywords']} keywords");
            }
            if ($what === 'docs') return Cli::OK;
        }
        if ($what === 'templates' || ($what === 'all' && $prefix === null && $remove === null)) {
            // Same as Tools > Developer > Templates (backend/tool_templates.php):
            // recompile, then compare var/templates
            $this->out('templates: ' . $this->get(Template::class)->rebuildFiles() . ' compiled');
            $result = $this->get(TemplateFiles::class)->sync();
            $this->out($result['locked'] ? 'templates: sync already running'
                : "templates: sync - created {$result['templates_created']}, updated {$result['templates_updated']}, deleted {$result['templates_deleted']}");
            foreach ($result['errors'] as $error) $this->out('  ' . $error);
            if ($what === 'templates') return Cli::OK;
        }
        if ($what !== 'html') {
            $cache = $this->get(SnippetCache::class);
            if ($remove !== null) {
                $this->out('snippets: removed ' . $cache->remove($remove) . " files of '$remove'");
            }
            else {
                if ($prefix !== null && !in_array($prefix, $cache->prefixes(), true)) {
                    return $this->usage("unknown snippet prefix '$prefix' (see: cache list)");
                }
                if ($prefix === null || str_starts_with($prefix, SnippetCache::CUSTOM_PREFIX . '.')) {
                    $legacy = $this->get(Snippets::class)->convertLegacy();
                    foreach ($legacy['converted'] as $line) $this->out("snippets: converted $line");
                    foreach ($legacy['errors'] as $line) $this->err("snippets: $line");
                }
                $result = $cache->build($prefix);
                $this->out("snippets: {$result['files']} files written" . ($prefix !== null ? " for '$prefix'" : ''));
                foreach ($result['dirs']['translation'] ?? [] as $file) {
                    $this->out("  translation: $file");
                }
                foreach ($result['blueprints'] as $file) {
                    $this->out("  blueprint: $file");
                }
                foreach ($result['errors'] as $error) {
                    $this->err("  ini: $error");
                }
                if ($result['duplicates']) {
                    $this->out('  keys in both lang/ and backend/lang/ (frontend file wins): '
                        . implode(', ', $result['duplicates']));
                }
                foreach ($result['missing'] as $lang => $keys) {
                    $this->out("  $lang: " . count($keys) . ' missing translations (en text used): ' . implode(', ', $keys));
                }
            }
        }
        if ($what !== 'snippets') {
            $cache = $this->get(HtmlCache::class);
            $htmlPrefix = fn(?string $p) => $p === 'core' ? '' : $p;
            if ($remove !== null) {
                $this->out('html: removed ' . $cache->remove($htmlPrefix($remove)) . " files of '$remove'");
            }
            else {
                if ($prefix !== null && !in_array($htmlPrefix($prefix), $cache->prefixes(), true)) {
                    return $this->usage("unknown html prefix '$prefix' (see: cache list)");
                }
                $result = $cache->build($htmlPrefix($prefix));
                $this->out("html: {$result['files']} files written" . ($prefix !== null ? " for '$prefix'" : ''));
            }
        }
        return Cli::OK;
    }
}
