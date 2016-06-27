<?php

namespace Gini\Controller\CGI\Settings;

use Gini\Controller\CGI\Layout\Common;

class ChemicalLimits extends Common
{
    public function __index($page = 1)
    {
        $me = _G('ME');
        $group = _G('GROUP');
        if (!$me->id || !$group->id || !$me->isAllowedTo('设置')) {
            $this->redirect('error/401');
        }

        $rpc = \Gini\Module\HazardousControlOrders::getRPC('chemical-limits');
        $limits = $rpc->admin->inventory->getGroupLimits($group->id);
        $page = (int) $page;
        $vars = [
            'limits' => $limits,
            'requestsHTML' => \Gini\CGI::request("ajax/settings/chemical-limits/requests-more/{$page}", $this->env)->execute()->content(),
        ];

        $this->view->body = V('settings/chemical-limits', $vars);
    }
}
