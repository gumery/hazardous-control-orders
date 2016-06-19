<?php

namespace Gini\Controller\CGI\Settings;

use \Gini\Controller\CGI\Layout\Common;

class ChemicalLimits extends Common {

    public function __index() {
        $me = _G('ME');
        $form = $this->form();
        $group_id = (int) \Gini\Gapper\Client::getGroupID();

        // if (!$me->isAllowedTo('设置')) {
        //     $this->redirect('error/401');
        // }
        //
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        }

        $vars = [
            'form' => $form,
        ];

        $this->view->body = V('settings/chemical-limits', $vars);
    }

}