<?php 

namespace Topxia\Service\System\Impl;

use Topxia\Service\Common\BaseService;
use Topxia\Service\System\StatisticsService;

class StatisticsServiceImpl extends BaseService implements StatisticsService
{
	public function getOnlineCount($retentionTime)
	{
		return $this->getSessionDao()->getOnlineCount($retentionTime);
	}

	private function getSessionDao()
	{
		return $this->createDao('System.SessionDao');
	}
}