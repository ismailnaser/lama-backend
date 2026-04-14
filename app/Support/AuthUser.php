<?php

namespace App\Support;

class AuthUser
{
    /**
     * @return object{id:int,name:string,username:string,email:string,role:string}|null
     */
    public static function fromRequest(\Illuminate\Http\Request $request): ?object
    {
        $u = $request->attributes->get('auth_user');
        if (!is_object($u)) return null;
        return $u;
    }
}

