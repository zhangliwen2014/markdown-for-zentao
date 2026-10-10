<?php
declare(strict_types=1);
/**
 * The control file of mdeditor module of ZenTaoPMS.
 *
 * Path B' 自有写路由（DESIGN §6.0 / findings §13）：
 * - create/edit 仅接收 POST，镜像 doc 模块保存链路（control.php:388-438 / 450-495 + zen.php responseAfterCreate/Edit）。
 * - content 字段声明 control=textarea 绕过 fixer L1（不 purify、不 htmlspecialchars）；
 *   L0 由 config/ext/mdeditor.php 的 URI 门控精准关闭，保证 markdown 原文逐字节入库。
 * - 权限经 $config->mdeditor->groupPrivs 委派给 doc（commonModel::hasPriv）。
 * - GET 请求一律拒绝（页面由核心 doc 新建/编辑页承担，JS 接管后仅把表单 POST 到本路由）。
 */
class mdeditor extends control
{
    /**
     * 新建 Markdown 文档（POST）。
     * Create a markdown doc.
     *
     * @access public
     * @return bool|int
     */
    public function create()
    {
        if(empty($_POST)) return $this->send(array('result' => 'fail', 'message' => 'mdeditor create accepts POST only.'));

        global $config;
        $this->app->loadModuleConfig('doc');
        $this->app->loadLang('doc');
        $docModel = $this->loadModel('doc');

        $libID  = (int)$this->post->lib;
        $docLib = $docModel->getLibByID($libID);
        if($docLib)
        {
            $canVisit = $this->checkPrivForCreate($docLib, (string)$docLib->type);
            if(!$canVisit) return $this->send(array('result' => 'fail', 'message' => $this->lang->doc->accessDenied));
        }

        $moduleID = $this->post->module;
        helper::setcookie('lastDocModule', $moduleID);
        if(!isset($_POST['lib']) && strpos((string)($_POST['module'] ?? ''), '_') !== false) list($_POST['lib'], $_POST['module']) = explode('_', $_POST['module'], 2);

        /* skipSpecial 必须在 form::data()（config() 填充 $this->data）之后调用：
         * fixer::processFields 会丢弃尚未存在于 data 中的字段，导致 config() 循环内
         * 的 textarea 自动 skipSpecial 静默失效、get() 逐字段 htmlspecialchars（实测 doc263 被实体化）。 */
        $docData = form::data($this->buildFormConfig('create'))
            ->setDefault('addedBy',  $this->app->user->account)
            ->setDefault('editedBy', $this->app->user->account)
            ->skipSpecial('content')
            ->get();

        $docResult = $docModel->create($docData, $this->post->labels);
        if(!$docResult || dao::isError()) return $this->send(array('result' => 'fail', 'message' => dao::getError()));

        $docID = $docResult['id'];
        $files = zget($docResult, 'files', '');

        $this->app->loadLang('action');
        $this->loadModel('action');
        $fileAction = !empty($files) ? $this->lang->addFiles . join(',', $files) . "\n" : '';
        $actionType = (($_POST['status'] ?? 'normal') == 'draft') ? 'savedDraft' : 'releasedDoc';
        $this->action->create('doc', $docID, $actionType, $fileAction);

        return $this->send(array('result' => 'success', 'message' => $this->lang->saveSuccess, 'id' => $docID, 'load' => $this->createLink('doc', 'view', "docID={$docID}")));
    }

    /**
     * 编辑 Markdown 文档（POST）。
     * Edit a markdown doc.
     *
     * @param  int    $docID
     * @access public
     * @return bool|int
     */
    public function edit(int $docID = 0)
    {
        if(empty($_POST)) return $this->send(array('result' => 'fail', 'message' => 'mdeditor edit accepts POST only.'));

        global $config;
        $this->app->loadModuleConfig('doc');
        $this->app->loadLang('doc');
        $docModel = $this->loadModel('doc');

        $doc = $docModel->getByID($docID);
        if(!$doc) return $this->send(array('result' => 'fail', 'message' => $this->lang->doc->accessDenied));

        $docData = form::data($this->buildFormConfig('edit'))
            ->setDefault('editedBy', $this->app->user->account)
            ->setIF(strpos(",{$doc->editedList},", ",{$this->app->user->account},") === false, 'editedList', $doc->editedList . ",{$this->app->user->account}")
            ->skipSpecial('content')
            ->get();

        $result = $docModel->update($docID, $docData);
        if(dao::isError())
        {
            if(!empty(dao::$errors['lib']) || !empty(dao::$errors['keywords'])) return $this->send(array('result' => 'fail', 'message' => dao::getError(), 'callback' => "zui.Modal.open({id: 'modalBasicInfo'});"));
            return $this->send(array('result' => 'fail', 'message' => dao::getError()));
        }
        if(!is_array($result)) return $this->send(array('result' => 'fail', 'message' => dao::getError()));

        $changes = $result['changes'];
        $files   = $result['files'];

        $this->app->loadLang('action');
        $this->loadModel('action');
        $comment = (string)($this->post->comment ?? '');
        if($comment != '' || !empty($changes) || !empty($files))
        {
            $actionType = 'Commented';
            if(!empty($changes))
            {
                $newType = (string)($_POST['status'] ?? $doc->status);
                if($doc->status == 'draft' && $newType == 'normal') $actionType = 'releasedDoc';
                if($doc->status == $newType)                        $actionType = 'Edited';
            }
            $fileAction = !empty($files) ? $this->lang->addFiles . join(',', $files) . "\n" : '';
            $actionID   = $this->action->create('doc', $doc->id, $actionType, $fileAction . $comment);
            if(!empty($changes)) $this->action->logHistory($actionID, $changes);
        }

        /* 与 docZen::responseAfterEdit 一致：无文档权限时回落空间列表链接（zen.php:630-645）。 */
        $link = $this->createLink('doc', 'view', "docID={$doc->id}");
        $doc  = $docModel->getByID($doc->id);
        $lib  = $docModel->getLibByID((int)$doc->lib);
        if(!$docModel->checkPrivDoc($doc))
        {
            $moduleName = 'doc';
            if($this->app->tab == 'execution')
            {
                $moduleName = 'execution';
                $methodName = 'doc';
            }
            else
            {
                $methodName = zget($this->config->doc->spaceMethod, $lib->type);
            }
            $objectID = zget($lib, $lib->type, 0);
            $link     = $this->createLink($moduleName, $methodName, "objectID={$objectID}&libID={$doc->lib}");
        }

        return $this->send(array('result' => 'success', 'message' => $this->lang->saveSuccess, 'load' => $link));
    }

    /**
     * .md 附件在线查看页（Vditor.preview 渲染，校验 file→doc 归属防 IDOR）。
     * M5 实现，当前占位。
     */
    public function viewfile()
    {
        return print('mdeditor: viewfile route under construction (M5)');
    }

    /**
     * 文档下载/导出（GET）。
     * - contentType 分派：markdown → .md，html → .html（最小 doctype 包装），其它 → .txt。
     * - 正文若引用本站上传文件（{id.ext}/data/upload/file-read/file-download），
     *   则打包 zip（正文 + files/），并把 URL 改写为 files/<sanitized>；无引用则单文件直发。
     * - 权限走 docModel::checkPrivDoc（与 doc::view 等价）。
     * Export the doc as file (single or zip with local assets).
     *
     * @param  int    $docID
     * @access public
     * @return void
     */
    public function export(int $docID = 0)
    {
        if(($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return print('mdeditor export accepts GET only.');

        $this->app->loadLang('doc');
        $this->app->loadLang('mdeditor');
        $this->app->loadModuleConfig('doc');
        $docModel = $this->loadModel('doc');

        $doc = $docModel->getByID($docID);
        if(!$doc || !$docModel->checkPrivDoc($doc)) return print((string)$this->lang->mdeditor->error->noPriv);

        $contentType = (string)($doc->contentType ?? 'html');
        $ext  = 'txt'; $mime = 'text/plain; charset=utf-8';
        if($contentType === 'markdown') { $ext = 'md';   $mime = 'text/markdown; charset=utf-8'; }
        elseif($contentType === 'html') { $ext = 'html'; $mime = 'text/html; charset=utf-8'; }

        $rawContent = (string)($doc->content ?? '');
        $body       = ($contentType === 'html' && stripos($rawContent, '<html') === false) ? $this->wrapHtmlExport($rawContent) : $rawContent;

        $baseName = $this->sanitizeFileName((string)($doc->title ?? ''));
        if($baseName === '') $baseName = 'doc-' . (int)$doc->id;

        /* 构造附件表：fileID → {realPath, rel, size}；正文改写命中的才进 zip。 */
        $files       = is_array($doc->files) ? $doc->files : array();
        $webRoot     = $this->app->getWebRoot();
        $rootPath    = '/' . ltrim($webRoot, '/');
        $companyID   = (int)$this->app->company->id;
        $uploadRootW = realpath($this->app->getWwwRoot() . 'data/upload');
        $rewrites    = array();
        $usedNames   = array();
        foreach($files as $fileID => $file)
        {
            $realPathRaw = (string)($file->realPath ?? '');
            if($realPathRaw === '' || !is_file($realPathRaw)) continue;
            $realPath = realpath($realPathRaw);
            if($realPath === false) continue;
            if($uploadRootW !== false && strpos($realPath, $uploadRootW) !== 0) continue;
            $size = @filesize($realPath);
            if($size === false || $size > 50 * 1024 * 1024) continue;

            $fileTitle = $this->sanitizeFileName((string)($file->title ?? ''));
            if($fileTitle === '') $fileTitle = 'file-' . (int)$fileID;
            $extension = preg_replace('/[^A-Za-z0-9]/', '', (string)($file->extension ?? ''));
            if($extension !== '' && !preg_match('/\.' . preg_quote($extension, '/') . '$/i', $fileTitle)) $fileTitle .= '.' . $extension;
            $unique = $fileTitle; $n = 2;
            while(isset($usedNames[$unique])) { $unique = preg_replace('/(\.[^.]+)?$/', "-{$n}$1", $fileTitle, 1); $n++; }
            $usedNames[$unique] = true;

            $pathName = (string)($file->pathname ?? '');
            $keys = array();
            if($pathName !== '')
            {
                $upRel = 'data/upload/' . $companyID . '/' . ltrim($pathName, '/');
                $keys[] = $upRel;
                $keys[] = rtrim($webRoot, '/') . '/' . $upRel;
                $keys[] = $rootPath . $upRel;
            }
            if($extension !== '')
            {
                $keys[] = '{' . $fileID . '.' . $extension . '}';
                $keys[] = rtrim($webRoot, '/') . '/file-read-' . $fileID . '.' . $extension . '.html';
                $keys[] = $rootPath . 'file-read-' . $fileID . '.' . $extension . '.html';
                $keys[] = rtrim($webRoot, '/') . '/file-download-' . $fileID . '.' . $extension . '.html';
                $keys[] = $rootPath . 'file-download-' . $fileID . '.' . $extension . '.html';
            }
            $keys[] = '{' . $fileID . '}';
            $keys[] = rtrim($webRoot, '/') . '/file-read-' . $fileID . '.html';
            $keys[] = $rootPath . 'file-read-' . $fileID . '.html';
            $keys[] = rtrim($webRoot, '/') . '/file-download-' . $fileID . '.html';
            $keys[] = $rootPath . 'file-download-' . $fileID . '.html';

            /* 绝对 URL：只按 path 尾段匹配（协议/主机无关）。 */
            foreach($keys as $k)
            {
                if($k === '' || strpos($body, $k) === false) continue;
                $rewrites[(int)$fileID] = array('realPath' => $realPath, 'rel' => 'files/' . $unique, 'size' => $size, 'keys' => array());
                break;
            }
            if(!isset($rewrites[(int)$fileID])) continue;

            /* 每个 fileID 独立记录命中 keys，用于精准替换（不同 key 若含 fileID 相同，替换顺序由长到短）。 */
            $hitKeys = array();
            foreach($keys as $k) if($k !== '' && strpos($body, $k) !== false) $hitKeys[] = $k;
            usort($hitKeys, fn($a, $b) => strlen($b) - strlen($a));
            $rewrites[(int)$fileID]['keys'] = $hitKeys;
        }

        if(empty($rewrites)) return $this->sendExportDownload($baseName . '.' . $ext, $mime, $body, 'content');

        foreach($rewrites as $r) foreach($r['keys'] as $k) $body = str_replace($k, $r['rel'], $body);

        $totalSize = strlen($body);
        foreach($rewrites as $r) $totalSize += $r['size'];
        if($totalSize > 200 * 1024 * 1024)
        {
            /* 单文件回落到原始未改写版本。 */
            $body = ($contentType === 'html' && stripos($rawContent, '<html') === false) ? $this->wrapHtmlExport($rawContent) : $rawContent;
            return $this->sendExportDownload($baseName . '.' . $ext, $mime, $body, 'content');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'mdexp') . '.zip';
        $zip = new ZipArchive();
        if($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true)
        {
            @unlink($zipPath);
            $body = ($contentType === 'html' && stripos($rawContent, '<html') === false) ? $this->wrapHtmlExport($rawContent) : $rawContent;
            return $this->sendExportDownload($baseName . '.' . $ext, $mime, $body, 'content');
        }
        $zip->addFromString($baseName . '.' . $ext, $body);
        foreach($rewrites as $r) $zip->addFile($r['realPath'], $r['rel']);
        $zip->close();

        $this->sendExportDownload($baseName . '.zip', 'application/zip', $zipPath, 'file');
        @unlink($zipPath);
    }

    /**
     * 最小 HTML 包装（contentType=html 时，DB 里只是 body 内容片段）。
     * Wrap bare HTML fragment with minimal doctype for offline viewing.
     *
     * @param  string $content
     * @access protected
     * @return string
     */
    protected function wrapHtmlExport(string $content): string
    {
        $charset = $this->config->charset ?? 'UTF-8';
        return "<!DOCTYPE html>\n<html><head><meta charset=\"{$charset}\"><title>doc</title></head><body>\n{$content}\n</body></html>\n";
    }

    /**
     * 文件名清洗：剥 Windows/POSIX 非法字符与控制字符，UTF-8 截断 100 字，去首尾点/空格。
     * Sanitize filename for cross-OS safety.
     *
     * @param  string $name
     * @access protected
     * @return string
     */
    protected function sanitizeFileName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        if($name === null) $name = '';
        $name = str_replace(array('\\', '/', ':', '*', '?', '"', '<', '>', '|'), '_', $name);
        $name = trim($name, " \t\n\r\0\x0B.");
        if(function_exists('mb_substr')) $name = mb_substr($name, 0, 100, 'UTF-8');
        else $name = substr($name, 0, 300);
        return trim($name, " \t\n\r\0\x0B.");
    }

    /**
     * 发送下载响应（自建 header，兼容中文文件名 RFC 5987；不复用 fileModel::sendDownHeader
     * 因其 Content-type 走 mimes 表，zip/md/html 走默认 octet-stream，浏览器不友好）。
     * Send download response with RFC 5987 UTF-8 filename support.
     *
     * @param  string $fileName
     * @param  string $mime
     * @param  string $payload content 字符串 或 file 路径
     * @param  string $type    'content'|'file'
     * @access protected
     * @return void
     */
    protected function sendExportDownload(string $fileName, string $mime, string $payload, string $type = 'content')
    {
        $obLevel = ob_get_level();
        for($i = 0; $i < $obLevel; $i++) ob_end_clean();

        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $fileName);
        $ascii = str_replace('"', '', $ascii);
        $utf8  = rawurlencode($fileName);

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . $utf8);
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cache-Control: no-cache, no-transform, must-revalidate');

        if($type === 'content')
        {
            header('Content-Length: ' . strlen($payload));
            echo $payload;
            \helper::end();
        }

        if($type === 'file' && is_file($payload))
        {
            set_time_limit(0);
            $size = @filesize($payload);
            if($size !== false) header('Content-Length: ' . $size);
            $handle = fopen($payload, 'rb');
            if($handle)
            {
                while(!feof($handle)) echo fread($handle, 10 * 1024 * 1024);
                fclose($handle);
            }
        }
        \helper::end();
    }

    /**
     * 复制核心 doc 表单配置并改造：content 走 textarea（绕 L1 过滤，findings §13），contentType 默认 markdown。
     * Copy core doc form config, switch content control to textarea and default contentType to markdown.
     *
     * @param  string $action create|edit
     * @access protected
     * @return array
     */
    protected function buildFormConfig(string $action): array
    {
        global $config;

        $formConfig = $config->doc->form->$action;
        $formConfig['content']['control'] = 'textarea';
        /* edit 配置无 contentType 键（update() 沿用旧版本 type），仅 create 有：注入 default 即可。 */
        if(isset($formConfig['contentType'])) $formConfig['contentType']['default'] = 'markdown';
        return $formConfig;
    }

    /**
     * 与 docZen::checkPrivForCreate 等价的库级可见性校验（zen.php:334，objectType 取库类型）。
     * Replicated lib visibility check for create.
     *
     * @param  object $doclib
     * @param  string $objectType
     * @access protected
     * @return bool
     */
    protected function checkPrivForCreate(object $doclib, string $objectType): bool
    {
        $canVisit = true;
        if(!empty($doclib->groups)) $groupAccounts = $this->loadModel('group')->getGroupAccounts(explode(',', $doclib->groups));
        switch($objectType)
        {
            case 'custom':
                $account = (string)$this->app->user->account;
                if(($doclib->acl == 'custom' || $doclib->acl == 'private') && strpos($doclib->users, $account) === false && $doclib->addedBy !== $account && !(isset($groupAccounts) && in_array($account, $groupAccounts, true))) $canVisit = false;
                break;
            case 'product':
                $canVisit = $this->loadModel('product')->checkPriv($doclib->product);
                break;
            case 'project':
                $canVisit = $this->loadModel('project')->checkPriv($doclib->project);
                break;
            case 'execution':
                $canVisit = $this->loadModel('execution')->checkPriv($doclib->execution);
                break;
            default:
            break;
        }
        return $canVisit;
    }
}
