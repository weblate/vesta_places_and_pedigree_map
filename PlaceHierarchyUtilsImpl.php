<?php

namespace Cissee\Webtrees\Module\PPM;

use Cissee\WebtreesExt\Http\Controllers\DefaultPlaceWithinHierarchy;
use Cissee\WebtreesExt\Http\Controllers\PlaceHierarchyUtils;
use Cissee\WebtreesExt\Http\Controllers\PlaceUrls;
use Cissee\WebtreesExt\Http\Controllers\PlaceWithinHierarchy;
use Cissee\WebtreesExt\MoreI18N;
use Fisharebest\Webtrees\Place;
use Fisharebest\Webtrees\Services\SearchService;
use Fisharebest\Webtrees\Statistics;
use Fisharebest\Webtrees\Tree;
use Illuminate\Support\Collection;

class PlaceHierarchyUtilsImpl implements PlaceHierarchyUtils {
  
  /** @var PlacesAndPedigreeMapModuleExtended */
  protected $module;
  
  /** @var Collection<PlaceHierarchyParticipant> */
  protected $participants;
          
  /** @var SearchService */
  protected $search_servicet;
     
  /** @var Statistics */
  protected $statistics;
  
  
  public function __construct(
          PlacesAndPedigreeMapModuleExtended $module,
          Collection $participants,
          SearchService $search_service, 
          Statistics $statistics) {
    
    $this->module = $module;
    $this->participants = $participants;
    $this->search_service = $search_service;
    $this->statistics = $statistics;
  }
  
  public function findPlace(int $id, Tree $tree, array $requestParameters): PlaceWithinHierarchy {
    $participantFilters = [];
    foreach ($this->participants as $participant) {
      $parameterName = $participant->filterParameterName();
      if (array_key_exists($parameterName, $requestParameters)) {
        $parameterValue = intVal($requestParameters[$parameterName]);
        $participantFilters[$parameterName] = $parameterValue;
      }
    }
    
    $urls = new PlaceUrls($this->module, $participantFilters, $this->participants);
    
    $first = null;
    $others = [];
    $otherParticipants = [];    
    foreach ($this->participants as $participant) {
      $pwh = $participant->findPlace($id, $tree, $urls);
      
      $parameterName = $participant->filterParameterName();
      $parameterValue = -1;
      if (array_key_exists($parameterName, $participantFilters)) {
        $parameterValue = intVal($participantFilters[$parameterName]);
      }
      
      if (($parameterValue === 1) && ($first === null)) {
        //no need to load non-specific!
        $first = $pwh;          
        //and no need to keep track of this participant
      } else {
        $otherParticipants[$parameterName] = $participant;
        $others[$parameterName] = $pwh;
      }
    }
    
    if ($first === null) {
      $actual = Place::find($id, $tree);
      $first = new DefaultPlaceWithinHierarchy($actual, $urls, $this->search_service, $this->statistics);
    }
    
    return new PlaceWithinHierarchyViaParticipants(
            $urls,
            $first, 
            new Collection($others), 
            new Collection($otherParticipants), 
            new Collection($participantFilters),
            $this->module);
  }
    
  public function hierarchyActionLabel(): string {
    return MoreI18N::xlate('Show place hierarchy');
  }
  
  public function listActionLabel(): string {
    return MoreI18N::xlate('Show all places in a list');
  }
  
  public function pageLabel(): string {
    return MoreI18N::xlate('Places');
  }
  
  public function placeHierarchyView(): string {
    return 'modules/generic-place-hierarchy/place-hierarchy';
  }
  
  public function listView(): string {
    return 'modules/generic-place-hierarchy/list';
  }
  
  public function pageView(): string {
    return 'modules/generic-place-hierarchy/page';
  }
  
  public function sidebarView(): string {
    return 'modules/generic-place-hierarchy/sidebar';
  }
}
