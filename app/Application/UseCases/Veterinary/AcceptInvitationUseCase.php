<?php

declare(strict_types=1);

namespace App\Application\UseCases\Veterinary;

use App\Core\Exceptions\VeterinaryDomainException;
use App\Core\Interfaces\IUserInvitationRepository;
use App\Models\User;
use App\Models\Veterinarian;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * ADR-33: the professional chooses their own password, and the producer never learns it.
 *
 * If the address already belongs to a user — the same veterinarian working for a second producer
 * — the invitation LINKS rather than duplicating: it adds the role on the new company over the
 * existing pivot. Duplicating the person would split their history in two.
 */
final class AcceptInvitationUseCase
{
    public function __construct(private readonly IUserInvitationRepository $invitations)
    {
    }

    /**
     * @throws VeterinaryDomainException
     */
    public function __invoke(string $plainToken, string $name, string $password): User
    {
        $invitation = $this->invitations->findByPlainToken($plainToken);

        if ($invitation === null || !$invitation->isUsable()) {
            throw VeterinaryDomainException::invitationNotUsable();
        }

        return DB::transaction(function () use ($invitation, $name, $password): User {
            $user = User::where('email', $invitation->getEmail())->first();

            if ($user === null) {
                $user = User::create([
                    'name' => trim($name) !== '' ? trim($name) : ($invitation->getVeterinarianName() ?? 'Profesional'),
                    'email' => $invitation->getEmail(),
                    'password' => Hash::make($password),
                ]);
            }

            // ADR-7: the portal role lives on the company pivot, so the same person can hold it
            // in more than one establishment.
            $alreadyLinked = DB::table('company_user')
                ->where('company_id', $invitation->getCompanyId())
                ->where('user_id', $user->id)
                ->exists();

            if ($alreadyLinked) {
                DB::table('company_user')
                    ->where('company_id', $invitation->getCompanyId())
                    ->where('user_id', $user->id)
                    ->update(['role' => 'veterinarian', 'updated_at' => now()]);
            } else {
                DB::table('company_user')->insert([
                    'company_id' => $invitation->getCompanyId(),
                    'user_id' => $user->id,
                    'role' => 'veterinarian',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Veterinarian::withoutGlobalScopes()
                ->where('id', $invitation->getVeterinarianId())
                ->update(['user_id' => $user->id]);

            $this->invitations->markAccepted((int) $invitation->getId(), (int) $user->id);

            return $user;
        });
    }
}
