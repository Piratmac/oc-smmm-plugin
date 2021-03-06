<?php namespace Piratmac\Smmm\Components;

use Cms\Classes\ComponentBase;
use Lang;
use Flash;
use Cms\Classes\Page;
use Piratmac\Smmm\Models\Portfolio as PortfolioModel;
use Piratmac\Smmm\Models\PortfolioMovement as PortfolioMovement;
use Piratmac\Smmm\Controllers\Portfolio as PortfolioController;
use Piratmac\Smmm\Controllers\PortfolioMovement as PortfolioMovementController;

use Illuminate\Support\Facades\Redirect;


class Portfolio extends ComponentBase
{

  /*
   * The portfolio being viewed / edited
   */
  public $portfolio;
  /*
   * Action: create, view or update
   */
  public $action = 'view';

  /*
   * Page listing the portfolios
   */
  public $portfolioListPage;

  /*
   * Page for asset details
   */
  public $assetDetailsPage;


  public function componentDetails()
  {
    return [
      'name'        => 'piratmac.smmm::lang.components.portfolio_name',
      'description' => 'piratmac.smmm::lang.components.portfolio_description'
    ];
  }

  public function defineProperties()
  {
    return [
      'portfolio_id' => [
        'title'       => 'piratmac.smmm::lang.settings.portfolio_id',
        'description' => 'piratmac.smmm::lang.settings.portfolio_id_description',
        'default'     => '{{ :portfolio_id }}',
        'type'        => 'string'
      ],
      'action' => [
        'title'       => 'piratmac.smmm::lang.settings.action',
        'description' => 'piratmac.smmm::lang.settings.action_description',
        'default'     => '{{ :action }}',
        'type'        => 'string'
      ],
      'portfolioList' => [
        'title'       => 'piratmac.smmm::lang.settings.portfoliolist_page',
        'description' => 'piratmac.smmm::lang.settings.portfoliolist_description',
        'default'     => '',
        'type'        => 'dropdown'
      ],
      'assetDetails' => [
        'title'       => 'piratmac.smmm::lang.settings.asset_page',
        'description' => 'piratmac.smmm::lang.settings.asset_description',
        'default'     => '',
        'type'        => 'dropdown'
      ],
    ];
  }
  public function getportfolioListOptions()
  {
    return Page::sortBy('baseFileName')->lists('baseFileName', 'baseFileName');
  }

  public function getassetDetailsOptions()
  {
    return Page::sortBy('baseFileName')->lists('baseFileName', 'baseFileName');
  }

  /*
   * Folder containing the images related to this plugin
   */
  public $imageFolder = '/plugins/piratmac/smmm/assets/images';


/**********************************************************************
                     Helper functions
**********************************************************************/

  protected function loadPortfolio($portfolio_id)
  {
    if ($portfolio_id == '' || !is_numeric($portfolio_id))
      throw new ApplicationException (trans('piratmac.smmm::lang.messages.error_no_id'));


    $portfolio = PortfolioModel::where('id', $portfolio_id)->firstOrFail();

    return $portfolio;
  }

  private function getPortfolioDetails ($portfolio)
  {
    $portfolio->getHeldAssets();
    $portfolio->setHeldAssetsLinks($this->controller, $this->assetDetailsPage);
    $portfolio->calculateValuation();
    $portfolio->calculateResults();
    $portfolio->getMovements();
    $portfolio->setMovementsLinks($this->controller, $this->assetDetailsPage);
    $this->prepareMovementForm();
    return $portfolio;
  }

  private function prepareMovementForm ()
  {
    $formController = new PortfolioMovementController();
    $formController->create('portfolio');
    $this->page['formPortfolioMovement'] = $formController;
  }

/**********************************************************************
                     User actions
**********************************************************************/
  public function onRun()
  {
    $this->addJs('/plugins/piratmac/smmm/assets/js/smmm.js');

    // Pickaday (for date picker)
    $this->addJs('/modules/system/assets/ui/js/foundation.baseclass.js');
    $this->addJs('/modules/system/assets/ui/js/foundation.controlutils.js');
    $this->addJs('/modules/system/assets/ui/vendor/moment/moment.js');
    $this->addJs('/modules/system/assets/ui/vendor/moment/moment-timezone-with-data.js');
    $this->addJs('/modules/system/assets/ui/vendor/pikaday/js/pikaday.js');
    $this->addJs('/modules/system/assets/ui/vendor/pikaday/js/pikaday.jquery.js');
    $this->addJs('/modules/system/assets/ui/js/datepicker.js');
    $this->addCss('/modules/system/assets/ui/vendor/pikaday/css/pikaday.css');

    $this->addJs('/modules/backend/formwidgets/recordfinder/assets/js/recordfinder.js', 'core');
    $this->addJs('/modules/system/assets/ui/js/input.trigger.js', 'core');

    $this->action = $this->param('action');
    $this->portfolioListPage = $this->property('portfolioList');
    $this->assetDetailsPage = $this->property('assetDetails');

    switch ($this->action) {
      case 'create':
      case 'update':
        # Generating the form
        $formController = new PortfolioController();
        if ($this->action == 'create')     $formController->create($this->action);
        elseif ($this->action == 'update') $formController->update($this->property('portfolio_id'), $this->action);
        $this->page['form'] = $formController;
        break;

      case 'view':
        $this->portfolio = $this->loadPortfolio($this->property('portfolio_id'));
        $this->portfolio = $this->getPortfolioDetails($this->portfolio);
        break;
    }
  }

  public function init () {
    $this->portfolioListPage = $this->property('portfolioList');
  }

  public function onUpdatePortfolio() {
    $this->portfolio = $this->loadPortfolio($this->property('portfolio_id'));
    $this->portfolio->update(post('Portfolio'));

    $url = $this->pageUrl($this->page->baseFileName, ['portfolio_id' => $this->portfolio->id, 'action' => 'view']);
    Flash::success(trans('piratmac.smmm::lang.messages.success_modification'));
    return Redirect::to($url);
  }

  public function onCreatePortfolio() {
    $this->portfolio = PortfolioModel::create(post('Portfolio'));

    $url = $this->pageUrl($this->page->baseFileName, ['portfolio_id' => $this->portfolio->id, 'action' => 'view']);
    Flash::success(trans('piratmac.smmm::lang.messages.success_creation'));
    return Redirect::to($url);
  }


  public function onDeletePortfolio() {
    $this->portfolio = $this->loadPortfolio($this->property('portfolio_id'));
    $this->portfolio->delete();

    $url = $this->pageUrl($this->portfolioListPage);
    Flash::success(trans('piratmac.smmm::lang.messages.success_deletion'));
    return Redirect::to($url);
  }


  public function onCreateMovement() {
    $this->portfolio = $this->loadPortfolio($this->property('portfolio_id'));

    // Workaround to avoid the asset / asset_id issue (the Form Fields behavior doesn't recognize it otherwise...)
    $defaults = array('portfolio_id' => $this->portfolio->id, 'asset_id' => post('PortfolioMovement[asset]'));
    $movement = PortfolioMovement::create($defaults + post('PortfolioMovement'));

    $url = $this->pageUrl($this->page->baseFileName, ['portfolio_id' => $this->portfolio->id, 'action' => 'view']);
    Flash::success(trans('piratmac.smmm::lang.messages.success_creation'));
    return Redirect::to($url);
  }


  public function onDeleteMovement() {
    $this->portfolio = $this->loadPortfolio($this->property('portfolio_id'));

    $movement = PortfolioMovement::where(['portfolio_id' => $this->portfolio->id, 'id' => post('movement_id')])->first();
    $movement->delete();


    $url = $this->pageUrl($this->page->baseFileName, ['portfolio_id' => $this->portfolio->id, 'action' => 'view']);
    Flash::success(trans('piratmac.smmm::lang.messages.success_deletion'));
    return Redirect::to($url);
  }
}