<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterMemberRequest;
use App\Models\User;
use App\Models\MemberProfile;
use App\Models\Role;
use App\Models\AccountStatus;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;


class AuthController extends Controller
{
    use ApiResponse;
    public function register(RegisterMemberRequest $request)
    {
        $memberRole = Role::where('name', 'Member')->firstOrFail();
        $pendingStatus = AccountStatus::where('name', 'Pending')->firstOrFail();

        $result = DB::transaction(function () use ($request, $memberRole, $pendingStatus) {

            $user = User::create([
                'email'             => $request->email,
                'password'          => Hash::make($request->password),
                'phone'             => $request->phone,
                'role_id'           => $memberRole->id,
                'account_status_id' => $pendingStatus->id,
            ]);

            $profile = MemberProfile::create([
                'user_id'              => $user->id,
                'sector_id'            => $request->sector_id,
                'location_id'          => $request->location_id,
                'name'                 => $request->name,
                'business_name'        => $request->business_name,
                'congregation'         => $request->congregation,
                'role_in_congregation' => $request->role_in_congregation,
                'description'          => $request->description,
                'website_url'          => $request->website_url,
                'address'              => $request->address,
            ]);

            return compact('user', 'profile');
        });

        return $this->successResponse(
            $result, 
            'Candidatura submetida com sucesso. A conta aguarda aprovação do administrador.', 
            Response::HTTP_CREATED
        );
    }
}
