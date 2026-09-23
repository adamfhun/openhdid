<?php

namespace App\Policies;

use App\Auth\Permission;

class QuestionPolicy extends PermissionPolicy
{
    protected Permission $view = Permission::QuestionsManage;

    protected ?Permission $manage = Permission::QuestionsManage;
}
