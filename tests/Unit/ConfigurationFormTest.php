<?php

declare(strict_types=1);

namespace Extalion\Sentry\Tests\Unit;

use Extalion\Sentry\Form\Type\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Validator\Validation;

final class ConfigurationFormTest extends TestCase
{
    private const DSN = 'https://public@example.ingest.sentry.io/1';

    public function testEmptyRatesStayEmptyInsteadOfBecomingZero(): void
    {
        $form = $this->formFactory()->create(Configuration::class, ['sample_rate' => '', 'traces_sample_rate' => '']);

        self::assertSame('', $form->get('sample_rate')->getViewData());
        self::assertSame('', $form->get('traces_sample_rate')->getViewData());

        $form->submit(['dsn' => self::DSN, 'sample_rate' => '', 'traces_sample_rate' => '']);

        self::assertNull($form->getData()['sample_rate']);
        self::assertNull($form->getData()['traces_sample_rate']);
    }

    public function testATracesSampleRateAboveOneIsRefused(): void
    {
        $form = $this->formFactory()->create(Configuration::class);
        $form->submit(['dsn' => self::DSN, 'traces_sample_rate' => '1.5']);

        self::assertFalse($form->isValid());
        self::assertGreaterThan(0, \count($form->get('traces_sample_rate')->getErrors()));
    }

    public function testATracesSampleRateIsKeptAsAFloat(): void
    {
        $form = $this->formFactory()->create(Configuration::class);
        $form->submit(['dsn' => self::DSN, 'traces_sample_rate' => '0.02']);

        self::assertTrue($form->isValid());
        self::assertSame(0.02, $form->getData()['traces_sample_rate']);
    }

    public function testSmallRatesKeepTheirDecimalsWhenThePageIsSavedAgain(): void
    {
        $locale = \Locale::getDefault();
        \Locale::setDefault('fr_FR');

        try {
            $form = $this->formFactory()->create(Configuration::class, ['sample_rate' => '0.0015', 'traces_sample_rate' => '0.0005']);

            self::assertSame('0,000500', $form->get('traces_sample_rate')->getViewData());

            $form->submit([
                'dsn' => self::DSN,
                'sample_rate' => $form->get('sample_rate')->getViewData(),
                'traces_sample_rate' => $form->get('traces_sample_rate')->getViewData(),
            ]);

            self::assertSame(0.0015, $form->getData()['sample_rate']);
            self::assertSame(0.0005, $form->getData()['traces_sample_rate']);
        } finally {
            \Locale::setDefault($locale);
        }
    }

    private function formFactory(): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }
}
