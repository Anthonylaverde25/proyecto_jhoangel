<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Venereal sampling requirements for breeding soundness
    |--------------------------------------------------------------------------
    |
    | Grounded in Carrillo (1988) "Manejo de un Rodeo de Cría", pp. 165-173, 207.
    | Pathogen codes must match `pathogens.code` as seeded by PathogenSeeder.
    |
    */
    'venereal' => [
        'required_pathogen_codes'  => ['TRITRICHOMONAS_FOETUS', 'CAMPYLOBACTER_FETUS'],
        'required_negative_rounds' => 2,
        'validity_days'            => 365,

        // Two-stage rollout switch: set to false to keep the legacy behaviour
        // (biometry-only aptitude) while historical protocols are being loaded.
        'enforce_sampling' => env('LIVESTOCK_ENFORCE_VENEREAL_SAMPLING', true),

        /*
         * ADR-17: only signed evidence grants aptitude.
         *
         * A determination counts when the extraction act that produced the tube is CONFIRMED, or
         * — for tubes predating the act model — when the laboratory report that resolved it is
         * CONFIRMED. Set to false to also accept loose historical samples backed by no document
         * at all, which is only ever appropriate while migrating legacy data.
         */
        'require_signed_evidence' => env('LIVESTOCK_REQUIRE_SIGNED_EVIDENCE', true),

    ],

    /*
    |--------------------------------------------------------------------------
    | Chain of custody (ADR-20 to ADR-28)
    |--------------------------------------------------------------------------
    |
    | The arrival of the tubes at the laboratory is an event of its own, and the two custody
    | modes differ in who is able to register it.
    |
    */
    'custody' => [
        /*
         * §11.6 reformulated: how many days after a dispatch an act with no result yet shows up
         * in the "despachadas sin informe" filter. A consultable threshold, never an automatic
         * alert: the number is a policy, and policies belong in configuration.
         */
        'arrival_overdue_days' => (int) env('LIVESTOCK_ARRIVAL_OVERDUE_DAYS', 7),

        /*
         * ADR-35 (rev.): a DERIVED result is a transcription of another institution's paper, and
         * that paper is the only thing holding it up.
         *
         * The trigger is the professional's own declaration, not a CUIT comparison. Comparing
         * CUITs was wrong: an employed veterinarian's CUIT differs from their laboratory's and
         * nothing was derived, so the comparison demanded somebody else's PDF for work signed at
         * their own bench.
         */
        'analysis_report_requires_attachment' => env('LIVESTOCK_ANALYSIS_REPORT_REQUIRES_ATTACHMENT', true),

        /*
         * §11.4: a broken cold chain does not block the record — the professional judges whether
         * the sample is still usable — but the judgement has to be written down.
         */
        'broken_cold_chain_requires_note' => env('LIVESTOCK_BROKEN_COLD_CHAIN_REQUIRES_NOTE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Which agents each assay rules out
    |--------------------------------------------------------------------------
    |
    | The chute sheet records that a sample was TAKEN, not what it found: results come back
    | from the laboratory days later. One physical tube can still rule out several agents
    | (a preputial scrape is cultured for both venereal agents), and the aptitude engine
    | counts negative rounds per agent, so each assay expands into one determination per
    | pathogen it covers.
    |
    */
    'sampling' => [
        'assay_pathogen_codes' => [
            'PREPUCE_SCRAPE'  => ['TRITRICHOMONAS_FOETUS', 'CAMPYLOBACTER_FETUS'],
            'BLOOD_SEROLOGY'  => ['BRUCELLA_ABORTUS'],
            'SEMEN_CULTURE'   => ['CAMPYLOBACTER_FETUS'],
            'TUBERCULIN_TEST' => ['MYCOBACTERIUM_BOVIS'],
        ],
    ],

    'biometry' => [
        'min_scrotal_circumference_cm' => 28.0,
        'min_body_condition_score'     => 2.0,
    ],

    'service_order' => [
        // ADR-5: fail-closed. A bull with no sanitary evaluation cannot enter a service order.
        'block_unevaluated_bulls' => env('LIVESTOCK_BLOCK_UNEVALUATED_BULLS', true),
    ],

    'attachments' => [
        'disk'             => 'tenant',
        'max_size_kb'      => 10240,
        'max_per_protocol' => 10,
        'allowed_mimes'    => ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'],

        // Signed download links are short lived: sanitary evidence has legal value.
        'signed_url_ttl_minutes' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Veterinary portal temporary access
    |--------------------------------------------------------------------------
    |
    | External professionals and health centres receive a one-off URL protected by a
    | temporary token instead of a system account. Only the SHA-256 hash is stored.
    |
    */
    'veterinary_portal' => [
        /*
         * Creating a veterinarian creates their portal account with this password, which the
         * producer hands over and the professional may change from their profile.
         *
         * KNOWN TRADE-OFF, decided deliberately: this account signs sanitary evidence, and a
         * shared default means anybody who knows the professional's email can sign in their
         * name. Revisit before this reaches real producers — the cheapest fix is forcing a
         * change before the first signature, which keeps this same handover.
         */
        'default_password' => env('LIVESTOCK_DEFAULT_VET_PASSWORD', '123456789'),

        /*
         * How long an invitation lasts. The invitation flow is no longer the default way in —
         * creating a professional creates their account — but it stays available for the day
         * the generic password is replaced by something each professional chooses.
         */
        'invitation_ttl_days'  => (int) env('LIVESTOCK_INVITATION_TTL_DAYS', 7),

        'token_bytes'          => 32,
        'default_ttl_hours'    => 72,
        'max_ttl_hours'        => 720,
        'default_max_uses'     => null,
        'public_url_template'  => env(
            'VETERINARY_PORTAL_URL_TEMPLATE',
            'http://localhost:3000/vet-portal/:token'
        ),
    ],
];
