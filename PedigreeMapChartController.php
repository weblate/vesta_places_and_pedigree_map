<?php

declare(strict_types=1);

namespace Cissee\Webtrees\Module\PPM;

use Cissee\WebtreesExt\Requests;
use Fig\Http\Message\StatusCodeInterface;
use Fisharebest\Webtrees\Exceptions\IndividualAccessDeniedException;
use Fisharebest\Webtrees\Exceptions\IndividualNotFoundException;
use Fisharebest\Webtrees\Http\Controllers\AbstractBaseController;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\PedigreeMapModule;
use Fisharebest\Webtrees\Services\ChartService;
use Fisharebest\Webtrees\Tree;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use Vesta\Hook\HookInterfaces\FunctionsPlaceUtils;
use Vesta\Model\MapCoordinates;
use Vesta\Model\PlaceStructure;
use function response;
use function view;

class PedigreeMapChartController extends AbstractBaseController {

  const LINE_COLORS = [
      '#FF0000',
      // Red
      '#00FF00',
      // Green
      '#0000FF',
      // Blue
      '#FFB300',
      // Gold
      '#00FFFF',
      // Cyan
      '#FF00FF',
      // Purple
      '#7777FF',
      // Light blue
      '#80FF80'
      // Light green
  ];

  //for getPreferences and other methods
  protected $module;
  protected $chart_service;

  public function __construct(
          PlacesAndPedigreeMapModuleExtended $module,
          ChartService $chart_service) {
    
    $this->module = $module;
    $this->chart_service = $chart_service;
  }

  //adapted from PedigreeMapModule
  public function page(ServerRequestInterface $request, Tree $tree): ResponseInterface {
    $xref = Requests::getString($request, 'xref');
    $individual = Individual::getInstance($xref, $tree);
    $maxgenerations = $tree->getPreference('MAX_PEDIGREE_GENERATIONS');
    $generations = Requests::getString($request, 'generations', $tree->getPreference('DEFAULT_PEDIGREE_GENERATIONS'));

    if ($individual === null) {
      throw new IndividualNotFoundException();
    }

    if (!$individual->canShow()) {
      throw new IndividualAccessDeniedException();
    }

    //HACK
    $currentRoute = $request->getQueryParams()['route'];
    
    return $this->viewResponse($this->module->name() . '::page', [
                'currentRoute' => $currentRoute,
                'module_name' => $this->module->name(),
                /* I18N: %s is an individual’s name */
                'title' => I18N::translate('Pedigree map of %s', $individual->fullName()),
                'tree' => $tree,
                'individual' => $individual,
                'generations' => $generations,
                'maxgenerations' => $maxgenerations,
                'map' => view($this->module->name() . '::chart',
                        [
                            'module'      => $this->module->name(),
                            'individual'  => $individual,
                            'type'        => 'pedigree',
                            'generations' => $generations,
                        ]
                ),
    ]);
  }

  //adapted from PedigreeMapModule
  public function mapData(ServerRequestInterface $request, Tree $tree): ResponseInterface {
    $pedigreeMapModule = new PedigreeMapModule($this->chart_service);

    $class = new ReflectionClass($pedigreeMapModule);
    $getPedigreeMapFactsMethod = $class->getMethod('getPedigreeMapFacts');
    $getPedigreeMapFactsMethod->setAccessible(true);
    $summaryDataMethod = $class->getMethod('summaryData');
    $summaryDataMethod->setAccessible(true);

    $xref = Requests::getString($request, 'reference');
    $indi = Individual::getInstance($xref, $tree);
    $color_count = count(self::LINE_COLORS);

    //$facts = $pedigreeMapModule->getPedigreeMapFacts($request, $this->chart_service);
    $facts = $getPedigreeMapFactsMethod->invoke($pedigreeMapModule, $request, $this->chart_service);

    $geojson = [
        'type' => 'FeatureCollection',
        'features' => [],
    ];

    $sosa_points = [];

    foreach ($facts as $id => $fact) {
      $latLon = $this->getLatLon($fact);

      $icon = ['color' => 'Gold', 'name' => 'bullseye '];
      if ($latLon !== null) {
        $latitude = $latLon->getLati();
        $longitude = $latLon->getLong();
        
        $polyline = null;
        $color = self::LINE_COLORS[log($id, 2) % $color_count];
        $icon['color'] = $color; //make icon color the same as the line
        $sosa_points[$id] = [$latitude, $longitude];
        $sosa_parent = intdiv($id, 2);
        if (array_key_exists($sosa_parent, $sosa_points)) {
          // Would like to use a GeometryCollection to hold LineStrings
          // rather than generate polylines but the MarkerCluster library
          // doesn't seem to like them
          $polyline = [
              'points' => [
                  $sosa_points[$sosa_parent],
                  [$latitude, $longitude],
              ],
              'options' => [
                  'color' => $color,
              ],
          ];
        }
        $geojson['features'][] = [
            'type' => 'Feature',
            'id' => $id,
            'valid' => true,
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [$longitude, $latitude],
            ],
            'properties' => [
                'polyline' => $polyline,
                'icon' => $icon,
                'tooltip' => strip_tags($fact->place()->fullName()),
                'summary' => view('modules/pedigree-map/events',
                        //$pedigreeMapModule->summaryData($indi, $fact, $id)),
                        $summaryDataMethod->invoke($pedigreeMapModule, $indi, $fact, $id)),
                'zoom' => /* $location->zoom() ?: */ 2,
            ],
        ];
      }
    }

    $code = empty($facts) ? StatusCodeInterface::STATUS_NO_CONTENT : StatusCodeInterface::STATUS_OK;

    return response($geojson, $code);
  }

  private function getLatLon($fact): ?MapCoordinates {
    $ps = PlaceStructure::fromFact($fact);
    return FunctionsPlaceUtils::plac2map($this->module, $ps, false);
  }

}
