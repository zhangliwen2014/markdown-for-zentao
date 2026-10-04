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
