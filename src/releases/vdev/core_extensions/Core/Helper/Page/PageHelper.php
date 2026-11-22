<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Page;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Application;
use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Lib\HtmlSanitizer\HtmlSanitizer;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderResult;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tools\ArrayTools;

class PageHelper extends BaseHelper
{
    public function getPage(int $pageId): ?Page
    {
        return $this->db->selectOrmOneOrNull(Page::class, 'SELECT * FROM pages WHERE id = %', [$pageId]);
    }

    public function getPageForUpdate(int $pageId): ?Page
    {
        return $this->db->selectOrmOneOrNull(Page::class, 'SELECT * FROM pages WHERE id = % FOR UPDATE', [$pageId]);
    }

    /** @return array<Page> */
    public function getPagesForExactSpace(int $spaceId): array
    {
        return $this->db->selectOrm(Page::class,
            'SELECT * FROM pages WHERE space_id = % ORDER BY name', [$spaceId]);
    }

    public function savePage(?Page $page, int $spaceId, object $data): Page
    {
        $document = $data->type === 2 ? PageDocument::normalize($data->data) : null;
        $json = $document === null ? null : PageSize::encodeDocument($document);

        // Le HTML saisi directement suit les mêmes règles que celui des composants.
        $rawHtml = $data->type === 1 ? HtmlSanitizer::sanitize((string) ($data->raw_html ?? '')) : null;

        if ($page === null) {
            $this->db->execute(
                'INSERT INTO pages (space_id, user_defined, name, description, type, raw_html, raw_scss, raw_css, data, access_edit_role_id) VALUES (%, %, %, %, %, %, %, %, %, %)',
                [$spaceId, 1, $data->name, $data->description, $data->type, $rawHtml,
                    $data->raw_scss, $data->raw_css, $json, $data->access_edit_role_id],
            );
            $pageId = $this->db->getLastInsertId();
        } else {
            $pageId = $page->id;
            $this->db->execute(
                'UPDATE pages SET name = %, description = %, type = %, raw_html = %, raw_scss = %, raw_css = %, data = %, access_edit_role_id = %, updated = CURRENT_TIMESTAMP WHERE id = %',
                [$data->name, $data->description, $data->type, $rawHtml, $data->raw_scss,
                    $data->raw_css, $json, $data->access_edit_role_id, $pageId],
            );
        }
        return $this->getPage($pageId);
    }

    public function updateStatus(int $pageId, int $status): void
    {
        $this->db->execute('UPDATE pages SET status = %, updated = CURRENT_TIMESTAMP WHERE id = %', [$status, $pageId]);
    }

    public function deleteTrashedPage(int $pageId): void
    {
        $this->db->execute('DELETE FROM pages WHERE id = % AND status = -2', [$pageId]);
    }

    public function updateCompiledCss(int $pageId, string $css): void
    {
        $this->db->execute('UPDATE pages SET raw_css = % WHERE id = %', [$css, $pageId]);
    }

    public function getPagesForSpace(?int $spaceId = null): array
    {
        $spaceIds = $this->coreSpaceHelper->getSpaceIdsWithParents($spaceId);

        return $this->db->selectOrm(
            Page::class,
            'SELECT * FROM pages WHERE space_id IN (%)',
            [FormatIn::get($spaceIds)],
        );
    }

    public function getPageMetadataByIdForSpace(?int $spaceId = null): array
    {
        $spaceIds = $this->coreSpaceHelper->getSpaceIdsWithParents($spaceId);

        return ArrayTools::indexArray($this->db->selectOrm(
            Page::class,
            'SELECT id,space_id,user_defined,name,description,created,updated,type,status,access_edit_role_id
                FROM pages WHERE space_id IN (%)',
            [FormatIn::get($spaceIds)],
        ), 'id');
    }

    public function renderPage(int $pageId): PageRenderResult
    {
        $page = $this->getPage($pageId);

        // Une référence supprimée, notamment dans un footer, ne bloque pas le rendu.
        if ($page === null) return new PageRenderResult('', false, false);
        
        $renderer = new PageRenderer($this);

        return $renderer->render($page);
    }

    

}
