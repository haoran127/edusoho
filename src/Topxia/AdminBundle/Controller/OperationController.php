<?php
namespace Topxia\AdminBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Topxia\Common\Paginator;
use Topxia\Common\ArrayToolkit;
use Topxia\Common\FileToolkit;
use Imagine\Gd\Imagine;
use Topxia\WebBundle\DataDict\ContentStatusDict;
use Topxia\WebBundle\DataDict\ContentTypeDict;
use Topxia\Service\Content\Type\ContentTypeFactory;

class OperationController extends BaseController
{

    public function indexAction(Request $request)
    {
        $conditions = $request->query->all();

        $categoryId = 0;
        if(!empty($conditions['categoryId'])){
            $conditions['includeChildren'] = true;
            $categoryId = $conditions['categoryId'];
        }

        $paginator = new Paginator(
            $request,
            $this->getArticleService()->searchArticlesCount($conditions),
            20
        );

        $articles = $this->getArticleService()->searchArticles(
            $conditions,
            'normal',
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );
        $categoryIds = ArrayToolkit::column($articles, 'categoryId');
        $categories = $this->getCategoryService()->findCategoriesByIds($categoryIds);
        $categoryTree = $this->getCategoryService()->getCategoryTree();

        return $this->render('TopxiaAdminBundle:Operation:index.html.twig',array(
            'articles' => $articles,
            'categories' => $categories,
            'paginator' => $paginator,
            'categoryTree'  => $categoryTree,
            'categoryId'  => $categoryId
        ));
    }

    public function articleCreateAction(Request $request)
    {
        if($request->getMethod() == 'POST'){
            $article = $request->request->all();
            $article['tags'] = array_filter(explode(',', $article['tags']));

            $article = $this->getArticleService()->createArticle($article);

            return $this->redirect($this->generateUrl('admin_operation'));
        }
        
        $categoryTree = $this->getCategoryService()->getCategoryTree();

        return $this->render('TopxiaAdminBundle:Operation:article-modal.html.twig',array(
            'categoryTree'  => $categoryTree,
            'category'  => array( 'id' =>0, 'parentId' =>0)
        ));
    }

    public function articleEditAction(Request $request, $id)
    {
        $article = $this->getArticleService()->getArticle($id);
        if (empty($article)) {
            throw $this->createNotFoundException('文章已删除或者未发布！');
        }
        if(empty($article['tagIds'])){
            $article['tagIds'] = array();
        }

        $tags = $this->getTagService()->findTagsByIds($article['tagIds']);
        $tagNames = ArrayToolkit::column($tags, 'name');

        $categoryId = $article['categoryId'];
        $category = $this->getCategoryService()->getCategory($categoryId);

        $categoryTree = $this->getCategoryService()->getCategoryTree();

        if ($request->getMethod() == 'POST') {
            $formData = $request->request->all();
            $article = $this->getArticleService()->updateArticle($id, $formData);
            return $this->redirect($this->generateUrl('admin_operation'));
        }
        return $this->render('TopxiaAdminBundle:Operation:article-modal.html.twig',array(
            'article' => $article,
            'categoryTree'  => $categoryTree,
            'category'  => $category,
            'tagNames' => $tagNames
        ));
    }

    public function showUploadAction(Request $request)
    {
        if ($request->getMethod() == 'POST') {

            $file = $request->files->get('picture');
            if (!FileToolkit::isImageFile($file)) {
                return $this->createMessageResponse('error', '上传图片格式错误，请上传jpg, gif, png格式的文件。');
            }

            $filenamePrefix = "article_";
            $hash = substr(md5($filenamePrefix . time()), -8);
            $ext = $file->getClientOriginalExtension();
            $filename = $filenamePrefix . $hash . '.' . $ext;

            $directory = $this->container->getParameter('topxia.upload.public_directory') . '/tmp';
            $file = $file->move($directory, $filename);
            $fileName = str_replace('.', '!', $file->getFilename());

            $articlePicture = $this->getPictureAtributes($fileName);

            return $this->render('TopxiaAdminBundle:Operation:article-picture-crop-modal.html.twig', array(
                'filename' => $fileName,
                'pictureUrl' => $articlePicture['pictureUrl'],
                'naturalSize' => $articlePicture['naturalSize'],
                'scaledSize' => $articlePicture['scaledSize']
            ));
        }

        return $this->render('TopxiaAdminBundle:Operation:aticle-picture-modal.html.twig', array(
            'pictureUrl' => "",
        ));
    }

    private function getPictureAtributes($filename)
    {
        $filename = str_replace('!', '.', $filename);
        $filename = str_replace(array('..' , '/', '\\'), '', $filename);
        $pictureFilePath = $this->container->getParameter('topxia.upload.public_directory') . '/tmp/' . $filename;

        try {
            $imagine = new Imagine();
            $image = $imagine->open($pictureFilePath);
        } catch (\Exception $e) {
            @unlink($pictureFilePath);
            return $this->createMessageResponse('error', '该文件为非图片格式文件，请重新上传。');
        }

        $naturalSize = $image->getSize();
        $scaledSize = $naturalSize->widen(270)->heighten(270);
        $pictureUrl = $this->container->getParameter('topxia.upload.public_url_path') . '/tmp/' . $filename;

        return array(
            'naturalSize' => $naturalSize,
            'scaledSize' => $scaledSize,
            'pictureUrl' => $pictureUrl
        );
    }

    public function pictureCropAction(Request $request)
    {

        if($request->getMethod() == 'POST') {
            $options = $request->request->all();
            $filename = $request->query->get('filename');
            $filename = str_replace('!', '.', $filename);
            $filename = str_replace(array('..' , '/', '\\'), '', $filename);
            $pictureFilePath = $this->container->getParameter('topxia.upload.public_directory') . '/tmp/' . $filename;
            $response = $this->getArticleService()->changeIndexPicture(realpath($pictureFilePath), $options);
            return new Response(json_encode($response));
        }
    }

    public function articleDeleteAction(Request $request)
    {
        $ids = $request->request->get('ids', array());
        $id = $request->query->get('id', null);
        
        if ($id) {
            array_push($ids, $id);
        }
        $result = $this->getArticleService()->deleteArticlesByIds($ids);
        if($result){
            return $this->createJsonResponse(array("status" =>"failed"));
        } else {
            return $this->createJsonResponse(array("status" =>"success")); 
        }
    }

    public function setArticlePropertyAction(Request $request,$id,$property)
    {
         $this->getArticleService()->setArticleProperty($id, $property);
         return $this->createJsonResponse(true); 
    }

    public function cancelArticlePropertyAction(Request $request,$id,$property)
    {
         $this->getArticleService()->cancelArticleProperty($id, $property);
         return $this->createJsonResponse(true);
    }

    public function articlePublishAction(Request $request, $id)
    {
        $this->getArticleService()->publishArticle($id);
        return $this->createJsonResponse(true);
    } 

    public function articleUnpublishAction(Request $request, $id)
    {
        $this->getArticleService()->unpublishArticle($id);
        return $this->createJsonResponse(true);
    }

    public function articleTrashAction(Request $request, $id)
    {
        $this->getArticleService()->trashArticle($id);
        return $this->createJsonResponse(true);
    }

    public function thumbRemoveAction(Request $Request,$id)
    {
        $this->getArticleService()->removeArticlethumb($id);
        return $this->createJsonResponse(true);
    }

    public function categoryIndexAction(Request $request)
    {   
        $categories = $this->getCategoryService()->getCategoryTree();
        
        return $this->render('TopxiaAdminBundle:Operation:category.index.html.twig', array(
            'categories' => $categories
        ));       
    }

    public function categoryCreateAction(Request $request)
    {

        if ($request->getMethod() == 'POST') {
            $category = $this->getCategoryService()->createCategory($request->request->all());
            return $this->renderTbody();
        }
        $category = array(
            'id' => 0,
            'name' => '',
            'code' => '',
            'parentId' => (int) $request->query->get('parentId', 0),
            'weight' => 0,
            'publishArticle' => 1,
            'seoTitle' => '',
            'seoKeyword' => '',
            'seoDesc' => '',
            'published' => 1
        );

        $categoryTree = $this->getCategoryService()->getCategoryTree();
        return $this->render('TopxiaAdminBundle:Operation:category-modal.html.twig', array(
            'category' => $category,
            'categoryTree'  => $categoryTree
        ));
    }

    public function categoryEditAction(Request $request, $id)
    {
        $category = $this->getCategoryService()->getCategory($id);
        if (empty($category)) {
            throw $this->createNotFoundException();
        }

        if ($request->getMethod() == 'POST') {
            $category = $this->getCategoryService()->updateCategory($id, $request->request->all());
            return $this->renderTbody();
        }
        $categoryTree = $this->getCategoryService()->getCategoryTree();
        
        return $this->render('TopxiaAdminBundle:Operation:category-modal.html.twig', array(
            'category' => $category,
            'categoryTree'  => $categoryTree
        ));
    }

    public function checkCodeAction(Request $request)
    {
        $code = $request->query->get('value');

        $exclude = $request->query->get('exclude');

        $avaliable = $this->getCategoryService()->isCategoryCodeAvaliable($code, $exclude);
  
        if ($avaliable) {
            $response = array('success' => true, 'message' => '');
        } else {
            $response = array('success' => false, 'message' => '编码已被占用，请换一个。');
        }

        return $this->createJsonResponse($response);
    }

    public function checkParentIdAction(Request $request)
    {
        $selectedParentId = $request->query->get('value');

        $currentId = $request->query->get('currentId');

        if($currentId == $selectedParentId && $selectedParentId != 0){
            $response = array('success' => false, 'message' => '不能选择自己作为父栏目');
        } else {
            $response = array('success' => true, 'message' => '');
        }

        return $this->createJsonResponse($response);
    }

    public function categoryDeleteAction(Request $request, $id)
    {
        $category = $this->getCategoryService()->getCategory($id);
        if (empty($category)) {
            throw $this->createNotFoundException();
        }

        if ($this->canDeleteCategory($id)) {
            return $this->createJsonResponse(array('status' => 'error', 'message'=>'此栏目有子栏目，无法删除'));
        } else {
            $this->getCategoryService()->deleteCategory($id);
            return $this->createJsonResponse(array('status' => 'success', 'message'=>'栏目已删除' ));
        }
        
    }

    public function groupIndexAction(Request $request)
    {
        $fields = $request->query->all();

        $conditions = array(
            'status'=>'',
            'title'=>'',
            'ownerName'=>'',
        );

        if(!empty($fields)){
            $conditions =$fields;
        } 

        $paginator = new Paginator(
            $this->get('request'),
            $this->getGroupService()->searchGroupsCount($conditions),
            10
        );

        $groupinfo=$this->getGroupService()->searchGroups(
                $conditions,
                array('createdTime','desc'),
                $paginator->getOffsetCount(),
                $paginator->getPerPageCount()
        );

        $ownerIds =  ArrayToolkit::column($groupinfo, 'ownerId');
        $owners = $this->getUserService()->findUsersByIds($ownerIds);

        return $this->render('TopxiaAdminBundle:Operation:group.index.html.twig',array(
            'groupinfo'=>$groupinfo,
            'owners'=>$owners,
            'paginator' => $paginator));
    }

    public function  closeGroupAction($id)
    {
        $this->getGroupService()->closeGroup($id);

        $groupinfo=$this->getGroupService()->getGroup($id);
        
        $owners=$this->getUserService()->findUsersByIds(array('0'=>$groupinfo['ownerId']));

        return $this->render('TopxiaAdminBundle:Operation:group-tr.html.twig', array(
            'group' => $groupinfo,
            'owners'=>$owners,
        ));
    }

    public function openGroupAction($id)
    {
        $this->getGroupService()->openGroup($id);

        $groupinfo=$this->getGroupService()->getGroup($id);

        $owners=$this->getUserService()->findUsersByIds(array('0'=>$groupinfo['ownerId']));

        return $this->render('TopxiaAdminBundle:Operation:group-tr.html.twig', array(
            'group' => $groupinfo,
            'owners'=>$owners,
        ));
    }

    public function transferGroupAction(Request $request,$groupId)
    {
        $data=$request->request->all();

        $user=$this->getUserService()->getUserByNickname($data['user']['nickname']);

        $group=$this->getGroupService()->getGroup($groupId);

        $ownerId=$group['ownerId'];

        $member=$this->getGroupService()->getMemberByGroupIdAndUserId($groupId,$ownerId);

        $this->getGroupService()->updateMember($member['id'],array('role'=>'member'));

        $this->getGroupService()->updateGroup($groupId,array('ownerId'=>$user['id']));

        $member=$this->getGroupService()->getMemberByGroupIdAndUserId($groupId,$user['id']);

        if($member){
            $this->getGroupService()->updateMember($member['id'],array('role'=>'owner'));
        }else{
            $this->getGroupService()->addOwner($groupId,$user['id']);
        }

        return new Response("success");
    }

    public function groupThreadAction(Request $request)
    {
        $fields = $request->query->all();

        $conditions = array(
            'status'=>'',
            'title'=>'',
            'groupName'=>'',
            'userName'=>'',
        );

        if(!empty($fields)){
            $conditions =$fields;
        }
        
        $paginator = new Paginator(
            $this->get('request'),
            $this->getThreadService()->searchThreadsCount($conditions),
            10
        );

        $threadinfo=$this->getThreadService()->searchThreads(
            $conditions,
            $this->filterSort('byCreatedTime'),
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );

        $memberIds = ArrayToolkit::column($threadinfo, 'userId');

        $owners = $this->getUserService()->findUsersByIds($memberIds);

        $groupIds =  ArrayToolkit::column($threadinfo, 'groupId');


        $group=$this->getGroupService()->getGroupsByIds($groupIds);

        return $this->render('TopxiaAdminBundle:Operation:group.thread.html.twig',array(
            'threadinfo'=>$threadinfo,
            'owners'=>$owners,
            'group'=>$group,
            'paginator' => $paginator));
    }

    public function batchDeleteThreadAction(Request $request)
    {
        $threadIds=$request->request->all();
        foreach ($threadIds['ID'] as $threadId) {
            $this->getThreadService()->deleteThread($threadId); 
        }
        return new Response('success');
    }

    public function removeEliteAction($threadId)
    {
        return $this->postAction($threadId,'removeElite');
    }

    public function setEliteAction($threadId)
    {
        return $this->postAction($threadId,'setElite');
    }

    public function removeStickAction($threadId)
    {
        return $this->postAction($threadId,'removeStick');
    }

    public function setStickAction($threadId)
    {
        return $this->postAction($threadId,'setStick');
    }

    public function closeThreadAction($threadId)
    {
        return $this->postAction($threadId,'closeThread');
    }

    public function openThreadAction($threadId)
    {
        return $this->postAction($threadId,'openThread');
    }

    public function deleteThreadAction($threadId)
    {   
        $thread=$this->getThreadService()->getThread($threadId);
        $threadUrl = $this->generateUrl('group_thread_show', array('id'=>$thread['groupId'],'threadId'=>$thread['id']), true);
        $this->getThreadService()->deleteThread($threadId);
        $this->getNotifiactionService()->notify($thread['userId'],'default',"您的话题<a href='{$threadUrl}' target='_blank'><strong>“{$thread['title']}”</strong></a>被管理员删除。");
        return $this->createJsonResponse('success');

    }

     private function postAction($threadId,$action)
    {
        $thread=$this->getThreadService()->getThread($threadId);
        $threadUrl = $this->generateUrl('group_thread_show', array('id'=>$thread['groupId'],'threadId'=>$thread['id']), true);
        
        if($action=='setElite'){
           $this->getThreadService()->setElite($threadId); 
           $this->getNotifiactionService()->notify($thread['userId'],'default',"您的话题<a href='{$threadUrl}' target='_blank'><strong>“{$thread['title']}”</strong></a>被设为精华。"); 
        }
        if($action=='removeElite'){
           $this->getThreadService()->removeElite($threadId); 
           $this->getNotifiactionService()->notify($thread['userId'],'default',"您的话题<a href='{$threadUrl}' target='_blank'><strong>“{$thread['title']}”</strong></a>被取消精华。"); 
        }
        if($action=='setStick'){
           $this->getThreadService()->setStick($threadId); 
           $this->getNotifiactionService()->notify($thread['userId'],'default',"您的话题<a href='{$threadUrl}' target='_blank'><strong>“{$thread['title']}”</strong></a>被置顶。"); 
        }
        if($action=='removeStick'){
           $this->getThreadService()->removeStick($threadId); 
           $this->getNotifiactionService()->notify($thread['userId'],'default',"您的话题<a href='{$threadUrl}' target='_blank'><strong>“{$thread['title']}”</strong></a>被取消置顶。");
        }
        if($action=='closeThread'){
           $this->getThreadService()->closeThread($threadId); 
           $this->getNotifiactionService()->notify($thread['userId'],'default',"您的话题<a href='{$threadUrl}' target='_blank'><strong>“{$thread['title']}”</strong></a>被关闭。");
        }
        if($action=='openThread'){
           $this->getThreadService()->openThread($threadId); 
           $this->getNotifiactionService()->notify($thread['userId'],'default',"您的话题<a href='{$threadUrl}' target='_blank'><strong>“{$thread['title']}”</strong></a>被打开。");
        }

        $thread=$this->getThreadService()->getThread($threadId);

        $owners=$this->getUserService()->findUsersByIds(array('0'=>$thread['userId']));

        $group=$this->getGroupService()->getGroupsByIds(array('0'=>$thread['groupId']));


        return $this->render('TopxiaAdminBundle:Operation:thread-table-tr.html.twig', array(
            'thread' => $thread,
            'owners'=>$owners,
            'group'=>$group,
        ));

    }

    public function canDeleteCategory($id)
    {
        return $this->getCategoryService()->findCategoriesCountByParentId($id);
    }

    public function blockIndexAction(Request $request)
    {
        $paginator = new Paginator(
            $this->get('request'),
            $this->getBlockService()->searchBlockCount(),
            20
        );

        $findedBlocks = $this->getBlockService()->searchBlocks($paginator->getOffsetCount(),
            $paginator->getPerPageCount());
        
        $latestBlockHistory = $this->getBlockService()->getLatestBlockHistory();
        $latestUpdateUser = $this->getUserService()->getUser($latestBlockHistory['userId']);

        return $this->render('TopxiaAdminBundle:Operation:block.index.html.twig', array(
            'blocks'=>$findedBlocks,
            'latestUpdateUser'=>$latestUpdateUser,
            'paginator' => $paginator
        ));
    }

    public function blockCreateAction(Request $request)
    {
        
        if ('POST' == $request->getMethod()) {
            $block = $this->getBlockService()->createBlock($request->request->all());
            $user = $this->getCurrentUser();
            $html = $this->renderView('TopxiaAdminBundle:Operation:block-tr.html.twig', array('block' => $block,'latestUpdateUser'=>$user));
            return $this->createJsonResponse(array('status' => 'ok', 'html' => $html));
        }

        $editBlock = array(
            'id' => 0,
            'title' => '',
            'code' => '',
            'mode' => 'html',
            'template' => ''
        );

        return $this->render('TopxiaAdminBundle:Operation:block-modal.html.twig', array(
            'editBlock' => $editBlock
        ));
    }

    public function blockUpdateAction(Request $request, $block)
    {
        if (is_numeric(($block))) {
            $block = $this->getBlockService()->getBlock($block);
        } else {
            $block = $this->getBlockService()->getBlockByCode($block);
        }

        $paginator = new Paginator(
            $this->get('request'),
            $this->getBlockService()->findBlockHistoryCountByBlockId($block['id']),
            5
        );
        
        $templateData = array();
        $templateItems = array();
        if ($block['mode'] == 'template') {
            $templateItems = $this->getBlockService()->generateBlockTemplateItems($block);
            $templateData = json_decode($block['templateData'],true);
        } 

        $blockHistorys = $this->getBlockService()->findBlockHistorysByBlockId(
            $block['id'], 
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount());

        foreach ($blockHistorys as &$blockHistory) {
            $blockHistory['templateData'] = json_decode($blockHistory['templateData'],true);
        }

        $historyUsers = $this->getUserService()->findUsersByIds(ArrayToolkit::column($blockHistorys, 'userId'));

        if ('POST' == $request->getMethod()) {
            $fields = $request->request->all();

            $templateData = array();
            if ($block['mode'] == 'template') {
                $template = $block['template'];
                
                $template = str_replace(array("(( "," ))","((  ","  )"),array("((","))","((","))"),$template); 
                
                $content = "";
                
                foreach ($fields as $key => $value) {   
                    $content = str_replace('(('.$key.'))', $value, $template);
                    break;
                };
                foreach ($fields as $key => $value) {   
                    $content = str_replace('(('.$key.'))', $value, $content);
                }
                $templateData = $fields;
                $fields = "";
                $fields['content'] = $content;
                $fields['templateData'] = json_encode($templateData);
            }
            
            $block = $this->getBlockService()->updateBlock($block['id'], $fields);
            $latestBlockHistory = $this->getBlockService()->getLatestBlockHistory();
            $latestUpdateUser = $this->getUserService()->getUser($latestBlockHistory['userId']);
            $html = $this->renderView('TopxiaAdminBundle:Operation:block-tr.html.twig', array(
                'block' => $block, 'latestUpdateUser'=>$latestUpdateUser
            ));
            return $this->createJsonResponse(array('status' => 'ok', 'html' => $html));          
        }

        return $this->render('TopxiaAdminBundle:Operation:block-update-modal.html.twig', array(
            'block' => $block,
            'blockHistorys' => $blockHistorys,
            'historyUsers' => $historyUsers,
            'paginator' => $paginator,
            'templateItems' => $templateItems,
            'templateData' => $templateData
        ));
    }

    public function previewAction(Request $request, $id)
    {
        $blockHistory = $this->getBlockService()->getBlockHistory($id);
        return $this->render('TopxiaAdminBundle:Operation:blockhistory-preview.html.twig', array(
            'blockHistory'=>$blockHistory
        ));
    }


    public function blockEditAction(Request $request, $block)
    {
        $block = $this->getBlockService()->getBlock($block);

        if ('POST' == $request->getMethod()) {

            $fields = $request->request->all();
            $block = $this->getBlockService()->updateBlock($block['id'], $fields);
            $user = $this->getCurrentUser();
            $html = $this->renderView('TopxiaAdminBundle:Operation:block-tr.html.twig', array(
                'block' => $block, 'latestUpdateUser'=>$user
            ));
            return $this->createJsonResponse(array('status' => 'ok', 'html' => $html));
        }

        return $this->render('TopxiaAdminBundle:Operation:block-modal.html.twig', array(
            'editBlock' => $block
        ));
    }

    public function checkBlockCodeForCreateAction(Request $request)
    {
        $code = $request->query->get('value');
        $blockByCode = $this->getBlockService()->getBlockByCode($code);
        if (empty($blockByCode)) {
            return $this->createJsonResponse(array('success' => true, 'message' => '此编码可以使用'));
        }
        return $this->createJsonResponse(array('success' => false, 'message' => '此编码已存在,不允许使用'));
    }

    public function checkBlockCodeForEditAction(Request $request, $id)
    {
        $code = $request->query->get('value');
        $blockByCode = $this->getBlockService()->getBlockByCode($code);
        if(empty($blockByCode)){
            return $this->createJsonResponse(array('success' => true, 'message' => 'ok'));
        } elseif ($id == $blockByCode['id']){
            return $this->createJsonResponse(array('success' => true, 'message' => 'ok'));
        } elseif ($id != $blockByCode['id']){
            return $this->createJsonResponse(array('success' => false, 'message' => '不允许设置为已存在的其他编码值'));
        }
    }

    public function blockDeleteAction(Request $request, $id)
    {
        try {
            $this->getBlockService()->deleteBlock($id);
            return $this->createJsonResponse(array('status' => 'ok'));
        } catch (ServiceException $e) {
            return $this->createJsonResponse(array('status' => 'error'));
        }
    }

    public function contentIndexAction(Request $request)
    {
        $conditions = array_filter($request->query->all());

        $paginator = new Paginator(
            $request,
            $this->getContentService()->searchContentCount($conditions),
            20
        );

        $contents = $this->getContentService()->searchContents(
            $conditions,
            array('createdTime', 'DESC'),
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );

        $userIds = ArrayToolkit::column($contents, 'userId');
        $users = $this->getUserService()->findUsersByIds($userIds);

        $categoryIds = ArrayToolkit::column($contents, 'categoryId');
        $categories = $this->getCategoryService()->findCategoriesByIds($categoryIds);

        return $this->render('TopxiaAdminBundle:Operation:content.index.html.twig',array(
            'contents' => $contents,
            'users' => $users,
            'categories' => $categories,
            'paginator' => $paginator,
        ));
    }

    public function contentCreateAction(Request $request, $type)
    {
        $type = ContentTypeFactory::create($type);
        if ($request->getMethod() == 'POST') {


            $content = $request->request->all();
            $content['type'] = $type->getAlias();

            $file = $request->files->get('picture');
            if(!empty($file)){
                $record = $this->getFileService()->uploadFile('default', $file);
                $content['picture'] = $record['uri'];
            }

            $content = $this->filterEditorField($content);

            $content = $this->getContentService()->createContent($this->convertContent($content));
            return $this->render('TopxiaAdminBundle:Operation:content-tr.html.twig',array(
                'content' => $content,
                'category' => $this->getCategoryService()->getCategory($content['categoryId']),
                'user' => $this->getCurrentUser(),
            ));
        }

        return $this->render('TopxiaAdminBundle:Operation:content-modal.html.twig',array(
            'type' => $type,
        ));
    }

    public function contentEditAction(Request $request, $id)
    {
        $content = $this->getContentService()->getContent($id);
        $type = ContentTypeFactory::create($content['type']);
        $record = array();
        if ($request->getMethod() == 'POST') {
            $file = $request->files->get('picture');
            if(!empty($file)){
                $record = $this->getFileService()->uploadFile('default', $file);
            }
            $content = $request->request->all();
            if(isset($record['uri'])){
                $content['picture'] = $record['uri'];
            }

            $content = $this->filterEditorField($content);

            $content = $this->getContentService()->updateContent($id, $this->convertContent($content));

            return $this->render('TopxiaAdminBundle:Operation:content-tr.html.twig',array(
                'content' => $content,
                'category' => $this->getCategoryService()->getCategory($content['categoryId']),
                'user' => $this->getCurrentUser(),
            ));
        }

        return $this->render('TopxiaAdminBundle:Operation:content-modal.html.twig',array(
            'type' => $type,
            'content' => $content,
        ));

    }

    public function contentPublishAction(Request $request, $id)
    {
        $this->getContentService()->publishContent($id);
        return $this->createJsonResponse(true);
    }

    public function contentTrashAction(Request $request, $id)
    {
        $this->getContentService()->trashContent($id);
        return $this->createJsonResponse(true);
    }

    public function contentDeleteAction(Request $request, $id)
    {
        $this->getContentService()->deleteContent($id);
        return $this->createJsonResponse(true);
    }

    public function contentAliasCheckAction(Request $request)
    {
        $value = $request->query->get('value');
        $thatValue = $request->query->get('that');

        if (empty($value)) {
            return $this->createJsonResponse(array('success' => true, 'message' => ''));
        }

        if ($value == $thatValue) {
            return $this->createJsonResponse(array('success' => true, 'message' => ''));
        }

        $avaliable = $this->getContentService()->isAliasAvaliable($value);
        if ($avaliable) {
            return $this->createJsonResponse(array('success' => true, 'message' => ''));
        }

        return $this->createJsonResponse(array('success' => false, 'message' => '该URL路径已存在'));
    }

    private function filterEditorField($content)
    {
        if($content['editor'] == 'richeditor'){
            $content['body'] = $content['richeditor-body'];
        } elseif ($content['editor'] == 'none') {
            $content['body'] = $content['noneeditor-body'];
        }

        unset($content['richeditor-body']);
        unset($content['noneeditor-body']);
        return $content;
    }

    private function convertContent($content)
    {
        if (isset($content['tags'])) {
            $tagNames = array_filter(explode(',', $content['tags']));
            $tags = $this->getTagService()->findTagsByNames($tagNames);
            $content['tagIds'] = ArrayToolkit::column($tags, 'id');
        } else {
            $content['tagIds'] = array();
        }

        $content['publishedTime'] = empty($content['publishedTime']) ? 0 : strtotime($content['publishedTime']);

        $content['promoted'] = empty($content['promoted']) ? 0 : 1;
        $content['sticky'] = empty($content['sticky']) ? 0 : 1;
        $content['featured'] = empty($content['featured']) ? 0 : 1;

        return $content;
    }

    public function couponIndexAction (Request $request)
    {   
        $conditions = $request->query->all();

        $paginator = new Paginator(
            $request,
            $this->getCouponService()->searchBatchsCount($conditions),
            20
        );

        $batchs = $this->getCouponService()->searchBatchs(
            $conditions, 
            array('createdTime', 'DESC'), 
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );
        return $this->render('TopxiaAdminBundle:Operation:coupon.index.html.twig', array(
           'batchs' => $batchs,
           'paginator' =>$paginator
        ));
    }

    public function couponGenerateAction (Request $request)
    {   
        if ('POST' == $request->getMethod()) {
            $couponData = $request->request->all();
            if ($couponData['type'] == 'minus') {
                $couponData['rate'] = $couponData['minus-rate'];
                unset($couponData['minus-rate']);
                unset($couponData['discount-rate']);
            } else {
                $couponData['rate'] = $couponData['discount-rate'];
                unset($couponData['minus-rate']);
                unset($couponData['discount-rate']);
            }

            if ($couponData['targetType'] == 'course')
            {
                $couponData['targetId'] = $couponData['courseId'];
                unset($couponData['courseId']);
            }

            $batch = $this->getCouponService()->generateCoupon($couponData);

            return $this->redirect($this->generateUrl('admin_operation_coupon'));
        }
        return $this->render('TopxiaAdminBundle:Operation:generate.html.twig');
    }

    public function couponCheckPrefixAction(Request $request)
    {
        $prefix = $request->query->get('value');
        $result = $this->getCouponService()->checkBatchPrefix($prefix);
        if ($result == true) {
            $response = array('success' => true, 'message' => '该前缀可以使用');
        } else {
            $response = array('success' => false, 'message' => '该前缀已存在');
        }
        return $this->createJsonResponse($response);
    }

    public function couponDetailAction(Request $request, $batchId)
    {   
        $count = $this->getCouponService()->searchCouponsCount(array('batchId' => $batchId));

        $batch = $this->getCouponService()->getBatch($batchId);

        $paginator = new Paginator($this->get('request'), $count, 20);

        $coupons = $this->getCouponService()->searchCoupons(
            array('batchId' => $batchId),
            array('createdTime', 'DESC'),
            $paginator->getOffsetCount(),  
            $paginator->getPerPageCount()
        );
        $users = $this->getUserService()->findUsersByIds(ArrayToolkit::column($coupons, 'userId'));

        $orders = $this->getOrderService()->findOrdersByIds(ArrayToolkit::column($coupons, 'orderId'));

        return $this->render('TopxiaAdminBundle:Operation:coupon-modal.html.twig', array(
            'coupons' => $coupons,
            'batch' => $batch,
            'paginator' => $paginator,
            'users' => $users,
            'orders' => $orders,
        ));
    }

    public function couponExportCsvAction(Request $request,$batchId)
    {
        $batch = $this->getCouponService()->getBatch($batchId);

        $coupons = $this->getCouponService()->findCouponsByBatchId(
            $batchId,
            0,
            $batch['generatedNum']
        );

        $coupons = array_map(function($coupon) {
            $export_coupon['batchId']  = $coupon['batchId'];
            $export_coupon['deadline'] = date('Y-m-d',$coupon['deadline']);
            $export_coupon['code']   = $coupon['code'];
            if ($coupon['status'] == 'unused') {
                $export_coupon['status'] = '未使用';
            } else {
                $export_coupon['status'] = '已使用'; 
            }
            return implode(',', $export_coupon);
        }, $coupons);

        $exportFilename = "couponBatch-".$batchId."-".date("YmdHi").".csv";

        $titles = array("批次","有效期至","优惠码","状态");

        $exportFile = $this->createExporteCSVResponse($titles, $coupons, $exportFilename);

        return $exportFile;
    }

    private function createExporteCSVResponse(array $header, array $data, $outputFilename)
    {   
        $header = implode(',', $header);

        $str = $header."\r\n";

        $str .= implode("\r\n", $data);

        $str = chr(239) . chr(187) . chr(191) . $str;

        $response = new Response();
        $response->headers->set('Content-type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$outputFilename.'"');
        $response->headers->set('Content-length', strlen($str));
        $response->setContent($str);

        return $response;
    }

    public function couponDeleteAction (Request $request,$id)
    {
        $result = $this->getCouponService()->deleteBatch($id);
        return $this->createJsonResponse(true);
    }

    public function queryIndexAction (Request $request)
    {   
        $conditions = $request->query->all();

        $paginator = new Paginator(
            $request,
            $this->getCouponService()->searchCouponsCount($conditions),
            20
        );

        $coupons = $this->getCouponService()->searchCoupons(
            $conditions,
            array('createdTime', 'DESC'),
            $paginator->getOffsetCount(),  
            $paginator->getPerPageCount()
        );
        $batchs = $this->getCouponService()->findBatchsbyIds(ArrayToolkit::column($coupons, 'batchId'));
        $users = $this->getUserService()->findUsersByIds(ArrayToolkit::column($coupons, 'userId'));
        $courses = $this->getCourseService()->findCoursesByIds(ArrayToolkit::column($coupons, 'targetId'));

        return $this->render('TopxiaAdminBundle:Operation:query.html.twig', array(
            'coupons' => $coupons,
            'paginator' => $paginator,
            'batchs' => $batchs,
            'users' => $users,
            'courses' =>$courses  
        ));
    }

    public function moneycardIndexAction(Request $request)
    {
        $conditions = $request->query->all();
        if (isset($conditions['batchName'])) {
            $conditions['batchName'] = "%".$conditions['batchName']."%";
        }
        $paginator = new Paginator(
            $this->get('request'),
            $this->getMoneyCardService()->searchBatchsCount($conditions),
            20
        );

        $batchs = $this->getMoneyCardService()->searchBatchs(
            $conditions,
            array('id', 'DESC'),
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );

        foreach ($batchs as $index => $batch) {
            $batchs[$index]['user'] = $this->getUserService()->getUser($batchs[$index]['userId']);
        }

        return $this->render('TopxiaAdminBundle:Operation:card.index.html.twig', array(
            'batchs'    => $batchs,
            'paginator' => $paginator
            ));
    }

    public function cardCreateAction(Request $request)
    {
        if ($request->getMethod() == 'POST') {
            $moneyCardData = $request->request->all();
            if ($moneyCardData['passwordLength']<6 || $moneyCardData['passwordLength']>32 ){
                throw new \RuntimeException('Bad passwordLength');
            }            
            if ($moneyCardData['cardLength']<6 || $moneyCardData['cardLength']>32 ){
                throw new \RuntimeException('Bad cardLength');
            }
            
            $batch = $this->getMoneyCardService()->createMoneyCard($moneyCardData);
            return $this->redirect($this->generateUrl('money_card_homepage'));
        }
        return $this->render('TopxiaAdminBundle:Operation:create-money-card-modal.html.twig');
    }

    public function cardPrefixCheckAction(Request $request)
    {
        $cardPrefixFilledByUser = strtolower($request->query->get('value')); 
        $response =  array('success' => true, 'message' => 'Allowed card prefix');
        $conditions = array('cardPrefix' => $cardPrefixFilledByUser);
        $cardPrefixCount = $this->getMoneyCardService()->searchBatchsCount($conditions);

        if  ($cardPrefixCount>0) {
            $response = array('success' => false, 'message' => '前缀已经存在');
        }else{
            $response = array('success' => true, 'message' => 'Good Prefix');
        }
        return $this->createJsonResponse($response);
    }

    public function cardExportCsvAction (Request $request, $batchId)
    {
        $batch = $this->getMoneyCardService()->getBatch($batchId);

        $moneyCards = $this->getMoneyCardService()->searchMoneyCards(
            array('batchId' => $batchId),
            array('id', 'DESC'),
            0,
            $batch['number']
        );
       
        $str = "卡号,密码,批次id,批次,有效期,状态,使用状态,使用者id,使用者,使用时间"."\r\n"; 

        $strMoneyCards = array();
        foreach ($moneyCards as $key => $moneyCard) {
            $card['cardId']   = $moneyCard['cardId'];
            $card['password'] = $moneyCard['password']; 
            $card['batchId']  = $moneyCard['batchId'];

            $cardBatch = $this->getMoneyCardService()->getBatch($moneyCard['batchId']);            
            $card['batchName']  = $cardBatch['batchName'];

            $card['deadline']  = $moneyCard['deadline'];
            $card['cardStatus'] = $moneyCard['cardStatus'] == 'normal'?'未作废':'已作废';
            $card['rechargeStatus'] = $moneyCard['rechargeUserId']==0?'未使用':'已使用';
            $card['rechargeUserId'] = $moneyCard['rechargeUserId']!=0?$moneyCard['rechargeUserId']:'--';
            $rechargeUser = $this->getUserService()->getUser($moneyCard['rechargeUserId']);
            $card['rechargeUserNickName'] = $moneyCard['rechargeUserId']==0?'--':$rechargeUser['nickname'];
            $card['rechargeTime'] = $moneyCard['rechargeUserId']==0?'--':date('Y-m-d H:i:s', $moneyCard['rechargeTime']);
            $strMoneyCards[] = implode(',',$card);
        }

        $str .= implode("\r\n",$strMoneyCards);
        $str=iconv('UTF-8','gb2312',$str);// utf-8(this file format) --> gb2312 (target format)

        $filename = "cards-".$batchId."-".date("YmdHi").".csv";

        $userId = $this->getCurrentUser()->id;
        $this->getLogService()->info('money_card_export', 'export', "导出了批次为{$batchId}的充值卡");

        $response = new Response();
        $response->headers->set('Content-type', 'text/csv');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        $response->headers->set('Content-length', strlen($str));
        $response->setContent($str);

        return $response;
    }

    public function deleteBatchAction (Request $request, $id)
    {
        if ($request->getMethod() == 'POST') {
            $this->getMoneyCardService()->deleteBatch($id);
        }

        return $this->redirect($this->generateUrl('admin_money_card_homepage'));
    }

    public function allCardsAction(Request $request)
    {
        $conditions = $request->query->all();
        $paginator = new Paginator(
            $this->get('request'),
            $this->getMoneyCardService()->searchMoneyCardsCount($conditions),
            20
        );

        $moneyCards = $this->getMoneyCardService()->searchMoneyCards(
            $conditions,
            array('id', 'DESC'),
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );

        foreach($moneyCards as $index => $moneyCard){
            $moneyCards[$index]['rechargeUser'] = $this->getUserService()->getUser($moneyCards[$index]['rechargeUserId']);
            $moneyCards[$index]['batch'] = $this->getMoneyCardService()->getBatch($moneyCards[$index]['batchId']);
        }
        return $this->render('TopxiaAdminBundle:Operation:all_cards.html.twig', array(
            'moneyCards'      => $moneyCards ,
            'paginator'  => $paginator
            ));

    }

    public function getPasswordAction (Request $request, $id)
    {
        $moneyCard = $this->getMoneyCardService()->getMoneyCard($id);

        $this->getLogService()->info('money_card', 'show_password', "查询了卡号为{$moneyCard['cardId']}密码");

        return $this->render('TopxiaAdminBundle:Operation:show-password-modal.html.twig', array(
            'moneyCardPassword' => $moneyCard['password']
        ));
    }

    public function cardDeleteAction (Request $request,$id)
    {
        if ($request->getMethod() == 'POST') {
            $moneyCard = $this->getMoneyCardService()->getMoneyCard($id);

            $this->getMoneyCardService()->deleteMoneyCard($id);
        }

        return $this->redirect($this->generateUrl('admin_money_card_all_cards'));
    }

    public function lockAction ($id)
    {
        $moneyCard = $this->getMoneyCardService()->lockMoneyCard($id);

        $moneyCard['rechargeUser'] = $this->getUserService()->getUser($moneyCard['rechargeUserId']);
        $moneyCard['batch'] = $this->getMoneyCardService()->getBatch($moneyCard['batchId']);

        return $this->render('TopxiaAdminBundle:Operation:money-card-table-tr.html.twig', array(
            'moneyCard' => $moneyCard,
        ));
    }

    public function unlockAction ($id)
    {
        $moneyCard = $this->getMoneyCardService()->unlockMoneyCard($id);
        $moneyCard['rechargeUser'] = $this->getUserService()->getUser($moneyCard['rechargeUserId']);
        $moneyCard['batch'] = $this->getMoneyCardService()->getBatch($moneyCard['batchId']);
        return $this->render('TopxiaAdminBundle:Operation:money-card-table-tr.html.twig', array(
            'moneyCard' => $moneyCard,
        ));
    }

    public function lockBatchAction ($id)
    {
        $batch = $this->getMoneyCardService()->lockBatch($id);
        $batch['user'] = $this->getUserService()->getUser($batch['userId']);

        return $this->render('TopxiaAdminBundle:Operation:batch-table-tr.html.twig', array(
            'batch' => $batch,
        ));
    }

    public function unlockBatchAction ($id)
    {
        $batch = $this->getMoneyCardService()->unlockBatch($id);
        $batch['user'] = $this->getUserService()->getUser($batch['userId']);

        return $this->render('TopxiaAdminBundle:Operation:batch-table-tr.html.twig', array(
            'batch' => $batch,
        ));
    }


    private function filterSort($sort)
    {
        switch ($sort) {
            case 'byPostNum':
                $orderBys=array(
                    array('isStick','DESC'),
                    array('postNum','DESC'),
                    array('createdTime','DESC'),
                );
                break;
            case 'byStick':
                $orderBys=array(
                    array('isStick','DESC'),
                    array('createdTime','DESC'),
                );
                break;
            case 'byCreatedTime':
                $orderBys=array(
                    array('createdTime','DESC'),
                );
                break;
            case 'byLastPostTime':
                $orderBys=array(
                    array('isStick','DESC'),
                    array('lastPostTime','DESC'),
                );
                break;
            case 'byPostNum':
                $orderBys=array(
                    array('isStick','DESC'),
                    array('postNum','DESC'),
                );
                break;
            default:
                throw $this->createServiceException('参数sort不正确。');
        }
        return $orderBys;
    }

     private function getArticleService()
    {
        return $this->getServiceKernel()->createService('Article.ArticleService');
    }

    private function getTagService()
    {
        return $this->getServiceKernel()->createService('Taxonomy.TagService');
    }

    private function getCategoryService()
    {
        return $this->getServiceKernel()->createService('Article.CategoryService');
    }

    private function getFileService()
    {
        return $this->getServiceKernel()->createService('Article.FileService');
    }

    private function getSettingService()
    {
        return $this->getServiceKernel()->createService('System.SettingService');
    }

    protected function getGroupService()
    {
        return $this->getServiceKernel()->createService('Group.GroupService');
    }

     protected function getThreadService()
    {
        return $this->getServiceKernel()->createService('Group.ThreadService');
    }

    protected function getNotifiactionService()
    {
        return $this->getServiceKernel()->createService('User.NotificationService');
    }

    protected function getBlockService()
    {
        return $this->getServiceKernel()->createService('Content.BlockService');
    }

    private function getContentService()
    {
        return $this->getServiceKernel()->createService('Content.ContentService');
    }

    private function getCouponService()
    {
        return $this->getServiceKernel()->createService('Coupon:Coupon.CouponService');
    }

    private function getOrderService()
    {
        return $this->getServiceKernel()->createService('Order.OrderService');
    }

    private function getCourseService()
    {
        return $this->getServiceKernel()->createService('Course.CourseService');
    }

    protected function getUserService()
    {
        return $this->getServiceKernel()->createService('User.UserService');
    }

    protected function getMoneyCardService()
    {
        return $this->getServiceKernel()->createService('MoneyCard.MoneyCardService');
    }

    protected function getLogService ()
    {
        return $this->getServiceKernel()->createService('System.LogService');
    }

    protected function getCashService()
    {
        return $this->getServiceKernel()->createService('Cash.CashService');
    }

}