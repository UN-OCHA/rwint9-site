<?php

// phpcs:ignoreFile

namespace Drupal\Tests\reliefweb_utility\ExistingSite;

use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use weitzman\DrupalTestTraits\ExistingSiteBase;

/**
 * Tests date helper.
 *
 * @covers \Drupal\reliefweb_utility\Plugin\Filter\ReliefwebTokenFilter
 * @coversDefaultClass \Drupal\reliefweb_utility\Plugin\Filter\ReliefwebTokenFilter
 */
class ReliefwebTokenFilterTest extends ExistingSiteBase {

  /**
   * The token service under test.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * An http client.
   */
  protected $httpClient;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $mock = new MockHandler([
      new Response(200, [], json_encode($this->getTestResponse())),
    ]);

    $handlerStack = HandlerStack::create($mock);
    $this->httpClient = new Client(['handler' => $handlerStack]);

    $this->container = $this->kernel->getContainer();
    $this->container->set('http_client', $this->httpClient);
    \Drupal::setContainer($this->container);

    $term = [
      'vocabulary' => 'disaster_type',
      'tid' => 9994648,
      'field_disaster_type_code' => [
        'value' => 'XX',
      ]
    ];

    if (!Term::load($term['tid'])) {
      $vocab = Vocabulary::load($term['vocabulary']);
      $this->createTerm($vocab, [
        'label' => 'Term ' . $term['tid'],
      ] + $term);
    }

    // Reset the token cache so that it can use the newly created term.
    drupal_static_reset('reliefweb_disaster_map_get_disaster_type_tokens');
    $this->token = \Drupal::token();
    $this->token->resetInfo();
  }

  /**
   * @covers ::process
   *
   * @dataProvider providerProcess
   *
   * @param string $html
   *   Input HTML.
   * @param string $expected
   *   The expected output string.
   */
  public function testProcess($text, $expected) {
    $test = check_markup($text, 'token_markdown');
    if (!is_string($test)) {
      $test = $test->__toString();
    }

    $this->assertStringContainsString($expected, $test);
  }

  /**
   * Provides data for testProcess.
   *
   * @return array
   *   An array of test data.
   */
  public function providerProcess() {
    return [
      [
        '',
        '',
      ],
      [
        '[disaster-map:XX]',
        'disaster-map-xx',
      ],
      [
        '[node:title]',
        '[node:title]',
      ],
    ];
  }

  /**
   * API test data.
   */
  private function getTestResponse() {
    return array(
      'time' => 8,
      'href' => 'https://api.reliefweb.int/v1/disasters?appname=reliefweb.int',
      'links' => array(
        'self' => array(
          'href' => 'https://api.reliefweb.int/v1/disasters?appname=reliefweb.int',
        ),
      ),
      'took' => 2,
      'totalCount' => 4,
      'count' => 4,
      'data' => array(
        array(
          'id' => '50894',
          'score' => 1,
          'fields' => array(
            'date' => array(
              'created' => '2021-10-02T00:00:00+00:00',
            ),
            'primary_type' => array(
              'code' => 'WF',
            ),
            'country' => array(
              array(
                'href' => 'https://api.reliefweb.int/v1/countries/116',
                'name' => 'Honduras',
                'id' => 116,
                'shortname' => 'Honduras',
                'iso3' => 'hnd',
                'primary' => TRUE,
              ),
            ),
            'url_alias' => 'http://rwint9-site.docksal/disaster/wf-2021-000154-hnd',
            'primary_country' => array(
              'href' => 'https://api.reliefweb.int/v1/countries/116',
              'name' => 'Honduras',
              'location' => array(
                'lon' => -86.62,
                'lat' => 14.82,
              ),
              'id' => 116,
              'shortname' => 'Honduras',
              'iso3' => 'hnd',
            ),
            'profile' => array(
              'overview-html' => "On Saturday, 2 October 2021, around 2:00 a.m., a fire ...",
            ),
            'name' => 'Honduras: Wildfires - Oct 2021',
            'id' => 50894,
            'type' => array(
              array(
                'code' => 'WF',
                'name' => 'Wild Fire',
                'id' => 4648,
                'primary' => TRUE,
              ),
            ),
            'status' => 'current',
          ),
          'href' => 'https://api.reliefweb.int/v1/disasters/50894',
        ),
        array(
          'id' => '50801',
          'score' => 1,
          'fields' => array(
            'date' => array(
              'created' => '2021-08-09T00:00:00+00:00',
            ),
            'primary_type' => array(
              'code' => 'WF',
            ),
            'country' => array(
              array(
                'href' => 'https://api.reliefweb.int/v1/countries/16',
                'name' => 'Algeria',
                'id' => 16,
                'shortname' => 'Algeria',
                'iso3' => 'dza',
                'primary' => TRUE,
              ),
            ),
            'url_alias' => 'http://rwint9-site.docksal/disaster/wf-2021-000115-dza',
            'primary_country' => array(
              'href' => 'https://api.reliefweb.int/v1/countries/16',
              'name' => 'Algeria',
              'location' => array(
                'lon' => 2.63,
                'lat' => 28.16,
              ),
              'id' => 16,
              'shortname' => 'Algeria',
              'iso3' => 'dza',
            ),
            'profile' => array(
              'overview-html' => "
    Wildfires have been affecting the Kabylia Region in northern Algeria since 9 August. More than 70 fires have occurred in 13 prefectures in the north of the country including Tizi-Ouzou, Bouira, Sétif, Khenchela, Guelma, Bejaïa, Bordj Bou Arreridj, Boumerdès, Tiaret, Medea, Tébessa, Blida and Skikda. According to media reports, more than 40 people have died as a result of the fires. The Algerian government has requested assistance from the international community in response to the fires, including through the EU Civil Protection Mechanism on 11 August for two Canadair aircraft to respond to fires in the Tizi Ouzou and Bejaïa regions. According to the European Forest Fire Information System (EFFIS), the fire risk will remain high to very extreme over the affected area. (ECHO, 11 Aug 2021)

    \n\n
    Fires raged in north and north-east of Algeria overnight on Monday 9 August 2021, and throughout Tuesday 10 August 2021, killing at least 69 people including 28 members of the People's National Army deployed as firefighters, rescuing over 100 people in Bejaia and Tizi-Ouzou. The governorates of Tizi-Ouzou, Bouira, Sétif, Khenchela, Guelma, Bejaia, Bordj Bou Arreridj, Boumerdes, Tiaret, Medea, Tebessa, Annaba, Souk Ahras, Ain Defla, Jijel, Batna, Blida and Skikda were affected by the fires. Algeria’s National Meteorology Office forecasted extremely hot weather through 12 August in nearly a dozen wilayas (governorates), including Tizi-Ouzou. The temperature was expected to reach 47 degrees Celsius in those wilayas, which are already suffering from severe water shortages. The Algerian Government mobilized the People’s National Army, dispatched 12 fire engines, and mobilized more than 900 firefighters to put out the fires and protect people and property. (IFRC, 18 Aug 2021)

    \n",
            ),
            'name' => 'Algeria: Wild Fires - Aug 2021',
            'id' => 50801,
            'type' => array(
              array(
                'code' => 'WF',
                'name' => 'Wild Fire',
                'id' => 4648,
                'primary' => TRUE,
              ),
            ),
            'status' => 'past',
          ),
          'href' => 'https://api.reliefweb.int/v1/disasters/50801',
        ),
        array(
          'id' => '50823',
          'score' => 1,
          'fields' => array(
            'date' => array(
              'created' => '2021-07-30T00:00:00+00:00',
            ),
            'primary_type' => array(
              'code' => 'WF',
            ),
            'country' => array(
              array(
                'href' => 'https://api.reliefweb.int/v1/countries/229',
                'name' => 'the Republic of North Macedonia',
                'id' => 229,
                'shortname' => 'North Macedonia',
                'iso3' => 'mkd',
                'primary' => TRUE,
              ),
            ),
            'url_alias' => 'http://rwint9-site.docksal/disaster/wf-2021-000109-mkd',
            'primary_country' => array(
              'href' => 'https://api.reliefweb.int/v1/countries/229',
              'name' => 'the Republic of North Macedonia',
              'location' => array(
                'lon' => 21.7,
                'lat' => 41.6,
              ),
              'id' => 229,
              'shortname' => 'North Macedonia',
              'iso3' => 'mkd',
            ),
            'profile' => array(
              'overview-html' => "
    Starting from 30 July 2021, the Republic of North Macedonia was hit by a heat wave that resulted in severe fires in several regions in the country. The fires have been raging for 16 days and are still not under control despite the enormous efforts of the state institutions responsible for crisis management as well as the local population. According to forecasts, the extremely hot weather is expected to continue until 25 August.

    \n\n
    The hot weather and high temperatures resulted in intensive recurring fires in many regions in the country in the last 12 days. The severe fires in numerous regions resulted in devastation of forests, fertile land, crops and property of the population. One casualty and several injured persons (inhaling smoke) have been reported. Numerous houses as well as other facilities have burnt down and were damaged in many villages.

    \n\n
    On 4 August 2021, the Government of the Republic of North Macedonia declared a state of crisis on the whole territory of the country for a period of 30 days. This is the trigger date for this DREF operation. There are still active fires in 3 locations as of 18 August. The situation cannot be predicted and may develop in different directions. The declared emergency situation in the country is currently until 30 August, with a possibility to be prolonged.

    \n\n
    The most affected regions are as follows: Strumica, Kochani, Kumanovo, Gevgelija, Valandovo, Bitola and Prilep, Shtip, Berovo, Pehchevo, Delchevo Skopje, Radovish, Ohrid, Kriva Palanka, Veles.

    \n\n
    The crisis management system of the country is coordinating efforts to put out the fires and to assist the affected population. Response teams from the Fire Brigade, the Crisis Management Centre, the Directorate for Protection and Rescue, the Army, and the Red Cross of the Republic of North Macedonia are coordinating efforts in the field in order to cope and respond to the crisis situation. However, due to the limited resources of the state for dealing with fires (no air tractors and only two army helicopters available for firefighting), an expansion of wildfires was observed almost on the whole territory of the country.

    \n\n
    The Red Cross of the Republic of North Macedonia (RCRNM) with all material and human resources, in frames of its possibilities, is participating in the overall efforts of the state authorities to respond to the crisis situation. The overall Red Cross operation is coordinated by the RCRNM Operational Centre which is responsible for coordination of the activities of the national society with the state authorities and the Red Cross branches. The Head of the RCRNM Operational Centre participates on a daily basis in the coordination meetings of the Centre for Crisis Management in order to coordinate the work of the National Society with the state agencies working in the field on national and local level.

    \n\n
    The weather forecast for the forthcoming days is extreme high temperatures with +40°C, which means that the situation with the raging fires would continue during the whole month of August. (IFRC, 20 Aug 2021)

    \n",
            ),
            'name' => 'North Macedonia: Wild Fires - Jul 2021',
            'id' => 50823,
            'type' => array(
              array(
                'code' => 'WF',
                'name' => 'Wild Fire',
                'id' => 4648,
                'primary' => TRUE,
              ),
            ),
            'status' => 'past',
          ),
          'href' => 'https://api.reliefweb.int/v1/disasters/50823',
        ),
        array(
          'id' => '50800',
          'score' => 1,
          'fields' => array(
            'date' => array(
              'created' => '2021-07-24T00:00:00+00:00',
            ),
            'primary_type' => array(
              'code' => 'WF',
            ),
            'country' => array(
              array(
                'href' => 'https://api.reliefweb.int/v1/countries/235',
                'name' => 'Tunisia',
                'id' => 235,
                'shortname' => 'Tunisia',
                'iso3' => 'tun',
                'primary' => TRUE,
              ),
            ),
            'url_alias' => 'http://rwint9-site.docksal/disaster/wf-2021-000106-tun',
            'primary_country' => array(
              'href' => 'https://api.reliefweb.int/v1/countries/235',
              'name' => 'Tunisia',
              'location' => array(
                'lon' => 9.56,
                'lat' => 34.11,
              ),
              'id' => 235,
              'shortname' => 'Tunisia',
              'iso3' => 'tun',
            ),
            'profile' => array(
              'overview-html' => "
    On Saturday, 24 July 2021, a fire broke out late in the afternoon in the pine forests of Ain Mazer, Sakiet Sidi Youssef district, Kef governorate in the middle-western region of Tunisia. Ain Mazer is a small village located in a rugged area, 18 kilometres from Sakkiet Sidi Youssef’s centre. Its population makes their living primarily through forestry, livestock, and crop farming. Simultaneously, another fire has erupted in Ghar Dimaa delegation, Jendouba Governorate, damaging over 1,500 hectares of Fajj Hessin forests. The fire destroyed 1,000 hectares of pine forest and continued until the evening of 27 July 2021, spreading up to Touiref, another village in the Kef governorate’s northern region. The fire in Touiref destroyed approximately 100 hectares of forest as well as ten homes and farms. (IFRC, 11 Aug 2021)

    \n",
            ),
            'name' => 'Tunisia: Wild Fires - Jul 2021',
            'id' => 50800,
            'type' => array(
              array(
                'code' => 'WF',
                'name' => 'Wild Fire',
                'id' => 4648,
                'primary' => TRUE,
              ),
            ),
            'status' => 'past',
          ),
          'href' => 'https://api.reliefweb.int/v1/disasters/50800',
        ),
      ),
    );
  }
}
