<?php

namespace Topxia\DataTag;

use Topxia\DataTag\DataTag;
use Topxia\Common\ArrayToolkit;

class RecommendTeachersDataTag extends CourseBaseDataTag implements DataTag  
{

    /**
     * 获取推荐老师列表
     *
     * 可传入的参数：
     *   count    必需 老师数量，取值不能超过100
     * 
     * @param  array $arguments 参数
     * @return array 推荐老师列表
     */
    public function getData(array $arguments)
    {	

        $this->checkCount($arguments);
        $conditions = array(
            'roles'=>'ROLE_TEACHER',
            'promoted'=>'1',
        );
        
    	$users = $this->getUserService()->searchUsers($conditions, array('promotedTime', 'DESC'), 0, $arguments['count']);

        return $this->unsetUserPasswords($users);
    }

}
