<?php
declare(strict_types=1);

namespace IwacSeo\Test\Service;

use IwacSeo\Service\VideoDescription;
use PHPUnit\Framework\TestCase;

final class VideoDescriptionTest extends TestCase
{
    public function testReadsABroadcastRecordOutInFrench(): void
    {
        // item 108664: a publisher, a day-precise date, a duration, a language
        // and a place — the shape of most of the 310 undescribed videos.
        $out = VideoDescription::compose([
            'publisher'  => 'RTB - Radiodiffusion Télévision du Burkina',
            'date'       => '2022-04-15',
            'duration'   => 'PT2M49S',
            'language'   => 'Français',
            'places'     => ['Burkina Faso'],
            'collection' => "Collection Islam Afrique de l'Ouest",
        ], 'fr');

        $this->assertSame(
            'Enregistrement vidéo (2 min 49 s) publié par RTB - Radiodiffusion Télévision du Burkina '
            . "le 15 avril 2022, en français. Lieux : Burkina Faso. Collection Islam Afrique de l'Ouest.",
            $out
        );
    }

    public function testReadsTheSameRecordOutInEnglish(): void
    {
        $out = VideoDescription::compose([
            'publisher'  => 'RTB - Radiodiffusion Télévision du Burkina',
            'date'       => '2022-04-15',
            'duration'   => 'PT2M49S',
            'language'   => 'French',
            'places'     => ['Burkina Faso'],
            'collection' => 'Islam West Africa Collection',
        ], 'en');

        $this->assertSame(
            'Video recording (2 min 49 s) published by RTB - Radiodiffusion Télévision du Burkina '
            . 'on 15 April 2022, in French. Places: Burkina Faso. Islam West Africa Collection.',
            $out
        );
    }

    public function testAuthorsComeBeforeThePublisherAndSubjectsGetTheirOwnSentence(): void
    {
        // item 15874: a DVD-digitised sermon with a preacher, a publisher, no
        // date, a whole-minute duration and literal subjects.
        $out = VideoDescription::compose([
            'authors'    => ['Muhammad Auwal Albani Zaria'],
            'publisher'  => 'Daarul Hadeethis Salafiyyah',
            'duration'   => 'PT181M',
            'language'   => 'Haoussa',
            'places'     => ['Zaria', 'Nigéria'],
            'subjects'   => ['Bauchi', "Wa'azi"],
        ], 'fr');

        $this->assertSame(
            'Enregistrement vidéo (181 min) par Muhammad Auwal Albani Zaria, publié par '
            . "Daarul Hadeethis Salafiyyah, en haoussa. Lieux : Zaria, Nigéria. Sujets : Bauchi, Wa'azi.",
            $out
        );
    }

    public function testSeveralAuthorsAreJoinedInTheLocale(): void
    {
        $this->assertSame(
            'Video recording by A, B and C.',
            VideoDescription::compose(['authors' => ['A', 'B', 'C']], 'en')
        );
        $this->assertSame(
            'Enregistrement vidéo par A et B.',
            VideoDescription::compose(['authors' => ['A', 'B']], 'fr')
        );
    }

    public function testADateWithoutAPublisherIsJustTheNextFact(): void
    {
        $this->assertSame(
            'Video recording by A, 15 April 2022.',
            VideoDescription::compose(['authors' => ['A'], 'date' => '2022-04-15'], 'en')
        );
    }

    public function testYearAndMonthPrecisionReadAsPeriodsNotDays(): void
    {
        // Six records are dated to the year or the month; "on 2022" is not a
        // sentence, and neither is a day the archive did not record.
        $this->assertSame(
            'Video recording published by P in 2022.',
            VideoDescription::compose(['publisher' => 'P', 'date' => '2022'], 'en')
        );
        $this->assertSame(
            'Enregistrement vidéo publié par P en avril 2022.',
            VideoDescription::compose(['publisher' => 'P', 'date' => '2022-04'], 'fr')
        );
    }

    public function testDurationsKeepTheUnitsTheArchiveRecorded(): void
    {
        $this->assertSame(
            'Video recording (1 h 2 min 3 s).',
            VideoDescription::compose(['duration' => 'PT1H2M3S'], 'en')
        );
        $this->assertSame(
            'Video recording (181 min).',
            VideoDescription::compose(['duration' => 'PT181M'], 'en')
        );
        // Not a time duration: dropped, and with nothing else to say, no sentence.
        $this->assertNull(VideoDescription::compose(['duration' => '3 hours'], 'en'));
        $this->assertNull(VideoDescription::compose(['duration' => 'P3D'], 'en'));
    }

    public function testNothingBeyondTheCollectionYieldsNoDescription(): void
    {
        // A sentence that only names the collection describes nothing; leaving
        // the field empty is more honest than filling it.
        $this->assertNull(VideoDescription::compose(['collection' => 'Islam West Africa Collection'], 'en'));
        $this->assertNull(VideoDescription::compose([], 'fr'));
        $this->assertNull(VideoDescription::compose(['authors' => ['', '  '], 'places' => []], 'fr'));
    }

    public function testLabelsAreDeduplicatedAndTrailingStopsNotDoubled(): void
    {
        $this->assertSame(
            'Video recording published by Radio Inc. Places: Niamey, Niger.',
            VideoDescription::compose([
                'publisher' => 'Radio Inc.',
                'places'    => ['Niamey', 'Niamey', 'Niger', ' '],
            ], 'en')
        );
    }

    public function testAnUnknownLocaleReadsInEnglish(): void
    {
        $this->assertSame(
            'Video recording published by P.',
            VideoDescription::compose(['publisher' => 'P'], 'de')
        );
    }

    public function testFrenchLowercasesTheLanguageLabelOnly(): void
    {
        // The label is a linked record's title, so it arrives capitalised;
        // French wants "en arabe", English keeps "in Arabic".
        $this->assertSame(
            'Enregistrement vidéo publié par P, en arabe.',
            VideoDescription::compose(['publisher' => 'P', 'language' => 'Arabe'], 'fr')
        );
        $this->assertSame(
            'Video recording published by P, in Arabic.',
            VideoDescription::compose(['publisher' => 'P', 'language' => 'Arabic'], 'en')
        );
    }
}
