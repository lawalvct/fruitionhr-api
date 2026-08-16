<?php

use App\Models\User;
use App\Modules\Auth\Notifications\EmailVerificationCodeNotification;
use App\Modules\Auth\Notifications\EssInvitationNotification;
use App\Modules\Auth\Services\EmailVerificationService;
use Tests\Support\MailRenderer;

test('every email carries the fruition logo and brand chrome', function (): void {
    $mails = [
        MailRenderer::render(new EmailVerificationCodeNotification('482913'), MailRenderer::recipient()),
        MailRenderer::render(new EssInvitationNotification('https://app.test/setup-account?token=abc', 'Fruition Foods Ltd'), MailRenderer::recipient()),
    ];

    foreach ($mails as $mail) {
        expect($mail['html'])
            ->toContain('/images/fruitionhr-logo-email.png')
            ->toContain('alt="FruitionHR"')
            // Brand bar segments from ../fruitionhr_brand_theme.md.
            ->toContain('#064E3B')
            ->toContain('#22C55E')
            ->toContain(config('mail.brand.support_email'))
            ->toContain('All rights reserved');

        // The theme must be inlined, otherwise clients strip the styling.
        expect($mail['html'])->toContain('style="');
    }
});

test('the verification email leads with the code and its expiry', function (): void {
    $mail = MailRenderer::render(new EmailVerificationCodeNotification('482913'), MailRenderer::recipient());

    expect($mail['subject'])->toBe('482913 is your FruitionHR verification code');

    expect($mail['html'])
        ->toContain('482913')
        ->toContain('Your verification code')
        ->toContain('This code expires in '.EmailVerificationService::CODE_TTL_MINUTES.' minutes')
        ->toContain('Fruition Foods Ltd workspace')
        ->toContain('Hi Adaeze,');

    // Preheader drives the inbox preview snippet.
    expect($mail['html'])->toContain('Your FruitionHR verification code is 482913');
});

test('the verification email falls back gracefully without a tenant', function (): void {
    $mail = MailRenderer::render(new EmailVerificationCodeNotification('123456'), MailRenderer::recipient(company: null));

    expect($mail['html'])
        ->toContain('open your workspace')
        ->not->toContain('the  workspace');
});

test('the verification email survives a notifiable with no tenant loaded', function (): void {
    // Model::shouldBeStrict() is on outside production: reading tenant_id on a
    // user hydrated without it (e.g. a factory-made user in ProfileTest) throws.
    $mail = MailRenderer::render(
        new EmailVerificationCodeNotification('123456'),
        new User(['name' => 'Solo User', 'email' => 'solo@example.test']),
    );

    expect($mail['html'])->toContain('open your workspace')->toContain('123456');
});

test('the ess invitation shows the setup link, company and expiry', function (): void {
    $url = 'https://app.test/setup-account?token=abc123';
    $mail = MailRenderer::render(new EssInvitationNotification($url, 'Fruition Foods Ltd'), MailRenderer::recipient('Chidi Okafor', 'chidi@example.test'));

    expect($mail['subject'])->toBe('Fruition Foods Ltd has invited you to FruitionHR');

    expect($mail['html'])
        ->toContain($url)
        ->toContain('Set my password')
        ->toContain('Sign-in email')
        ->toContain('chidi@example.test')
        ->toContain('60 minutes')
        ->toContain('Hi Chidi,');
});

test('the plain text alternative is free of markdown syntax', function (): void {
    $mails = [
        MailRenderer::render(new EmailVerificationCodeNotification('482913'), MailRenderer::recipient()),
        MailRenderer::render(new EssInvitationNotification('https://app.test/setup-account?token=abc', 'Fruition Foods Ltd'), MailRenderer::recipient()),
    ];

    foreach ($mails as $mail) {
        expect($mail['text'])
            ->not->toContain('**')
            ->not->toContain('# ')
            ->not->toMatch('/\]\(/')
            ->toContain('FruitionHR — Empowering Your Workforce');
    }
});

test('the mail preview gallery renders outside production', function (): void {
    $this->get('/debug/emails')->assertOk()->assertSee('Email templates');

    $this->get('/debug/emails/verification-code')
        ->assertOk()
        ->assertSee('/images/fruitionhr-logo-email.png', false);

    $this->get('/debug/emails/ess-invitation?format=text')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8');

    $this->get('/debug/emails/does-not-exist')->assertNotFound();
});
