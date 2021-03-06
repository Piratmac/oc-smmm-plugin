<?php namespace Piratmac\Smmm\Models;

use Model;
use Db;
use \October\Rain\Database\Traits\SoftDeleting;
use Auth;
use October\Rain\Exception\ValidationException;
use October\Rain\Exception\ApplicationException;

/**
 * Portfolio Model
 */
class Portfolio extends Model
{

  use \October\Rain\Database\Traits\Validation;
  use SoftDeleting;

  /**
   * @var string The database table used by the model.
   */
  public $table = 'piratmac_smmm_portfolios';

  /**
   * @var array Guarded fields
   */
  protected $guarded = [];

  /**
   * @var array Fillable fields
   */
  protected $fillable = ['description', 'open_date', 'close_date', 'broker', 'number', 'base_currency'];


  protected $rules = [
    'description' => 'required',
    'open_date' => 'required|date',
    'close_date' => 'sometimes|date|after:open_date',

  ];

  /**
   * @var array Relations
   */
  public $hasOne = [];
  public $hasMany = ['movements' => 'Piratmac\Smmm\Models\PortfolioMovement'];
  public $belongsTo = [
    'base_currency' => ['Piratmac\Smmm\Models\Asset', 'order' => 'title ASC', 'conditions' => 'type = \'cash\''],
  ];
  public $belongsToMany = [
      'heldAssets' => [
        'Piratmac\Smmm\Models\Asset',
        'table'    => 'piratmac_smmm_portfolio_contents',
        'pivot' => ['date_from', 'date_to', 'asset_count', 'average_price_tag']
    ]];
  public $morphTo = [];
  public $morphOne = [];
  public $morphMany = [];
  public $attachOne = [];
  public $attachMany = [];


  /**
   * @var array Contents of the portfolio
   */
  public $contents = [];

  /**
   * @var array Movements related to the portfolio
   */
  public $movements = [];

  /**
   * @var array Total value of the portfolio
   */
  public $balance = [];

  /**
   * @var array Total money invested in the portfolio
   */
  public $moneyInvested = [];

  /**
   * @var array Overall results of the portfolio (small-scale performance factor)
   */
  public $results = [];


/**********************************************************************
                       Navigation
**********************************************************************/

  /**
   * Sets the "url" attribute with a URL to this object
   * @param string $pageName
   * @param Cms\Classes\Controller $controller
   */
  public function setUrl($controller, $page)
  {
      $params_view = [
          'portfolio_id' => $this->id,
          'action'       => 'view',
      ];
      $params_update = [
          'portfolio_id' => $this->id,
          'action'       => 'update',
      ];

      $this->url_view   = $controller->pageUrl($page, $params_view);
      $this->url_update = $controller->pageUrl($page, $params_update);
  }


/**********************************************************************
                       Portfolio valuation
**********************************************************************/
  /**
  * Determines the contents of the portfolio
  * @param date The date at which to determine the contents
  */
  public function getHeldAssets ($date = 0, $asset_id = NULL) {
    if ($date == 0 || !strtotime($date) || !isset($date))
      $date = date('Y-m-d');
    $this->contents = $this->heldAssets()
                           ->where('date_from', '<=', $date)
                           ->where('date_to', '>=', $date)
                           ->orderBy('type', 'DESC')
                           ->orderBy('title', 'ASC')
                           ->with([
                              'value' => function ($query) use ($date) {
                                $query->where('date', '<=', $date)
                                      ->where('date', '>', date('Y-m-d', strtotime($date . ' 2 months ago')))
                                      ->orderBy('asset_id')
                                      ->orderBy('date', 'DESC')
                                      ->first();
                              }
                            ])
                           ;

    if (!is_null($asset_id))
      $this->contents->where('asset_id', $asset_id);

    $this->contents = $this->contents->get();

    // Getting the latest value
    $heldAssetsIds = [];
    $this->contents->each(function ($heldAsset) use (&$heldAssetsIds, $date) {
      $heldAssetsIds[] = $heldAsset->id;

      // If we found a value, in the right currency
      if ($heldAsset->baseCurrency == $this->base_currency && $heldAsset->value->first() !== null)
        $heldAsset->lastValue = ['value' => $heldAsset->value->first()->value,
                                 'date'  => $heldAsset->value->first()->date  ];
      else {
        $value = $heldAsset->getValueAsOfDate($date, $this->base_currency);
        $heldAsset->lastValue = ['value' => $value->value,
                                 'date'  => $value->date  ];
      }
    });


/*
    // I'm not particularly proud of this solution.
    // Basically the first query looks for the most probable case (= there is a value in the last 2 months)
    // If there is no result, a separate query is then executed.
    // It could be performed with a single JOIN query with sub-selects, but I couldn't find how to do it through OctoberCMS
    $values = AssetValue::whereIn('asset_id', $heldAssetsIds)
                          ->where('date', '<=', $date)
                          ->where('date', '>', date('Y-m-d', strtotime($date . ' 2 months ago')))
                          ->orderBy('asset_id')
                          ->orderBy('date', 'ASC')
                          ->get();
    $values = $values->keyBy('asset_id');


    $this->contents->each (function ($heldAsset) use ($values, $date) {
      $value = $heldAsset->value->first();
      if (!isset($value))
        $value = $heldAsset->getValueAsOfDate($date);
      if (isset($value))
        $heldAsset->lastValue = ['value' => $value->value,
                                 'date' => $value->date ];
    });
*/
    return $this->contents;

  }
  /**
  * Calculates various amounts related to the portfolio
  */
  public function calculateValuation () {
    if ($this->contents->count() == 0) return;

    $this->contents->each(function ($heldAsset) {
        // Calculate the price (cost) of the asset
      $heldAsset->pivot->totalBuyPrice = $heldAsset->pivot->average_price_tag * $heldAsset->pivot->asset_count;

      if (!isset($this->moneyInvested[$heldAsset->type])) $this->moneyInvested[$heldAsset->type] = 0;
      $this->moneyInvested[$heldAsset->type] += $heldAsset->pivot->totalBuyPrice;

      if (!isset($this->moneyInvested['total'])) $this->moneyInvested['total'] = 0;
      $this->moneyInvested['total'] += $heldAsset->pivot->totalBuyPrice;

      // Valuation of the portfolio
      $heldAsset->pivot->valueDate = $heldAsset->lastValue['date'];
      $heldAsset->pivot->unitValue = $heldAsset->lastValue['value'];
      $heldAsset->pivot->totalValue = $heldAsset->lastValue['value'] * $heldAsset->pivot->asset_count;

      if (!isset($this->balance[$heldAsset->type])) $this->balance[$heldAsset->type] = 0;
      $this->balance[$heldAsset->type] += $heldAsset->pivot->totalValue;

      if (!isset($this->balance['total'])) $this->balance['total'] = 0;
      $this->balance['total'] += $heldAsset->pivot->totalValue;
    });

    return $this->contents;
  }


  /**
  * Calculates "performance" of the portfolio
  */
  public function calculateResults () {
    $totals = $this->movements()
                   ->select(Db::raw('type, sum(asset_count * unit_value) as sum_value, sum(unit_gain_upon_sell * asset_count) as sum_gains'))
                   ->groupBy('type')
                   ->get();

    $totals = $totals->keyBy('type');

    if (!key_exists('total', $this->balance)) $this->balance['total'] = 0;
    if (!key_exists('total', $this->moneyInvested)) $this->moneyInvested['total'] = 0;

    $this->results = [
      'total_deposits' => isset($totals['cash_entry'])?$totals['cash_entry']->sum_value:0,

      'total_withdrawals' => isset($totals['cash_exit'])?$totals['cash_exit']->sum_value:0,
      'expected_gain' => [
        'amount' => $this->balance['total'] - $this->moneyInvested['total'],
        'percent' => $this->moneyInvested['total']==0?'N/A': ($this->balance['total'] - $this->moneyInvested['total']) / $this->moneyInvested['total'] * 100,
      ],
      'actual_gain' => [
        'amount' => (isset($totals['asset_sell'])?$totals['asset_sell']->sum_gains:0),
        'percent' => 'N/A'
      ]
    ];

    if ($this->moneyInvested['total'] != 0)
      $this->results['actual_gain']['percent'] = $this->results['actual_gain']['amount'] / $this->moneyInvested['total'] * 100;
  }


  /**
  * Sets all URLs for the assets
  * @return 0 if no error occurred
  */
  public function setHeldAssetsLinks ($controller, $page) {
    if ($this->contents->count() > 0) {
      $this->contents->each(function($heldAsset) use($controller, $page) {
        $heldAsset->setUrl($controller, $page);
      });
    }
    return 0;
  }

  /**
  * Updates all held asset values after the given date
  * @param movementDate The date of the movement
  * @param changeInCount The change in asset_count to be applied
  * @param asset The asset being modified
  */
  public function updateFutureBalance ($movement, $changeInCount, $asset) {
/**************************************************************************
 * This function performs a lot of operations on the asset history
 * Please find below some examples to help understand what happens
 **********************************
 * Case 1
 *  A new movement is added where there was no data
 *
 *  Timeline (days)      | 1 | 2 | 3 | 4 | 5 | 6 | 7 |
 *  Existing records:               [None]
 *  New movement is added:       ^ (+ 200)
 *  Expected result:             | 200   --> (to infinity and beyond)
 *
 *  Operations to perform:
 *    1. Enter the new value
 **********************************
 * Case 2
 *  A new movement is added in the middle of existing data
 *
 *  Timeline (days)      | 1 | 2 | 3 | 4 | 5 | 6 | 7 |
 *  Existing records:    |      100      |     50    |
 *  New movement is added:       ^ (+ 200)
 *  Expected result:     |   100 | 300   |    250    |
 *
 *  Operations to perform:
 *    1. Split the existing movement
 *    2. Modify the end date of the first part
 *    3. Modify the asset_count after day 3 (so that 50 becomes 50 + 200)
 *    4. Insert the new record
 **********************************
 * Case 3
 *  A new movement is added and erases an existing one
 *
 *  Timeline (days)      | 1 | 2 | 3 | 4 | 5 | 6 | 7 |
 *  Existing records:    |     100   | 10|     50    |
 *  New movement is added:           ^ (+ 200)
 *  Expected result:     |   100     |210|    250    |
 *
 *  Operations to perform:
 *    1. Split the existing movement
 *    2. Modify the asset_count on or after day 4 (so that 10 becomes 210 and 50 becomes 250)
 **********************************
 * Case 4
 *  A new movement is added strictly before an existing one
 *
 *  Timeline (days)      | 1 | 2 | 3 | 4 | 5 | 6 | 7 |
 *  Existing records:                |         50    |
 *  New movement is added:   ^ (+ 200)
 *  Expected result:         |  200  |        250    |
 *
 *  Operations to perform:
 *    1. Create the new movement (beware of the date)
 *    2. Modify the asset_count on or after day 4 (so that 50 becomes 250)
 **************************************************************************
 */

    // Find the record currently existing, so that we can set up the new one properly
    // Example: case 2 step 1 and case 3 step 1
    $currentBalance = $this->heldAssets()
                           ->wherePivot('date_from', '<=', $movement->date)
                           ->wherePivot('date_to',   '>=', $movement->date)
                           ->wherePivot('asset_id', $asset)
                           ->first();

    if (!is_null($currentBalance)) {
      // Creating a new record that starts after the existing (modified) one
      // Example: Case 2 step 4
      // As of now all data except dates are identical to the existing balance (they'll get updated later)
      $newBalanceData = [
        'date_from'    => $movement->date,
        'date_to'      => $currentBalance->pivot->date_to,
        'asset_count'  => $currentBalance->pivot->asset_count,
        'average_price_tag' => $currentBalance->pivot->average_price_tag,
      ];
      if ($newBalanceData['date_from'] != $currentBalance->pivot->date_from)
        $this->heldAssets()->attach($asset, $newBalanceData);


      // Updating the existing record that overlaps the new one AND starts before it (only the date_to changes)
      // Example: Case 2 step 2
      $newDate = date('Y-m-d', strtotime($movement->date.' a day ago'));
      $this->heldAssets()
           ->wherePivot('date_from', '<', $movement->date)
           ->wherePivot('date_to', '>=', $movement->date)
           ->wherePivot('asset_id', $asset)
           ->update(['date_to' => $newDate]);
    }
    else {
      // There is no balance valid at current date
      // We need to check if there is a future balance that may be impacted
      $futureBalance = $this->heldAssets()
                            ->wherePivot('date_from', '>', $movement->date)
                            ->wherePivot('asset_id', $asset)
                            ->orderBy('date_from')
                            ->first();

      if (!is_null($futureBalance)) {
        // There is another balance valid only after the current date ==> we need to stop the new balance before that
        // Example: Case 4 step 1
        $newBalanceData = [
          'date_from'    => $movement->date,
          'date_to'      => date('Y-m-d', strtotime($futureBalance->pivot->date_from.' a day ago')),
          'asset_count'  => 0,
          'average_price_tag' => 0,
        ];
        $this->heldAssets()->attach($asset, $newBalanceData);
      }
      else {
        // There is no future balance impacted ==> we just register the new one
        $newBalanceData = [
          'date_from'    => $movement->date,
          'date_to'      => '9999-12-31',
          'asset_count'  => 0,
          'average_price_tag' => $movement->unit_value,
        ];
        $this->heldAssets()->attach($asset, $newBalanceData);
      }
    }

    // Updating the average price tag for assets: it is equal to :
    // (existing_price * existing_units + movement_price*movement_units) / (existing_units + movement_units)
    if ($asset != $this->baseCurrency) {
      $impactedAssetBalance = $this->heldAssets()
                                   ->wherePivot('date_to',   '>=', $movement->date)
                                   ->wherePivot('asset_id', $asset)
                                   ->get();

      if (!is_null($impactedAssetBalance) && $movement->type == 'asset_buy') {
        foreach ($impactedAssetBalance as $impactedBalance) {
          if ($impactedBalance->pivot->asset_count + $movement->asset_count != 0) {
            $impactedBalance->pivot->average_price_tag =
              ($impactedBalance->pivot->average_price_tag * $impactedBalance->pivot->asset_count +
               $movement->unit_value                      * $movement->asset_count)
              / ($impactedBalance->pivot->asset_count + $movement->asset_count);
            $this->heldAssets()
                 ->wherePivot('date_from', $impactedBalance->pivot->date_from)
                 ->wherePivot('date_to',   $impactedBalance->pivot->date_to)
                 ->wherePivot('asset_id',  $impactedBalance->pivot->asset_id)
                 ->update(['average_price_tag' => $impactedBalance->pivot->average_price_tag]);
          }
        }
      }
    }

    // Modify the asset count of any record that ends after the movement date (as they will all be impacted)
    // Example: Case 2 step 3
    $this->heldAssets()
         ->wherePivot('date_to', '>=', $movement->date)
         ->wherePivot('asset_id', $asset)
         ->increment('asset_count', $changeInCount);
  }






/**********************************************************************
                       Portfolio movements
**********************************************************************/
  /**
  * Gets the movements related to the account
  * @param dateFrom The earliest date - default to 01/01/1970
  * @param dateTo The last date - defaults to maximum date
  */
  public function getMovements ($dateFrom = 0, $dateTo = 0) {
    $query = $this->movements()->with(array('asset' => function($query) {
      $query->withTrashed();
    }));

    if ($dateFrom != 0 && strtotime($dateFrom) && isset($dateFrom))
      $query->where('date_from', '>=', $dateFrom);
    if ($dateTo != 0 && strtotime($dateTo) && isset($dateTo))
      $query->where('date_to', '<=', $dateTo);

    $this->movements = $query->getQuery()->orderBy('date', 'DESC')->paginate(15);


    $movementDates = $this->movements->map(function ($movement) { return $movement->date; });
    $maxDate = $movementDates->first();
    $minDate = $movementDates->last();

    if (count($movementDates) > 0) {
      $cashBalance = $this->heldAssets()
                          ->where('asset_id', $this->baseCurrency)
                          ->where('date_from', '<=', $maxDate)
                          ->where(function ($query) use($minDate) {
                             $query->where('date_to', '>=', $minDate)
                                   ->orWherenull('date_to');})
                          ->get();

      $this->movements->each(function ($movement) use ($cashBalance) {
        $movement->cashBalance = $cashBalance->filter (function ($balance) use ($movement) {
          return (strtotime($balance->pivot->date_from) <= strtotime($movement->date) && strtotime($balance->pivot->date_to) >= strtotime($movement->date));
          })
          ->map (function ($balance) { return $balance->pivot->asset_count; })->first();
      });
    }
  }

  /**
  * Sets all URLs for the assets linked to movements
  * @return 0 if no error occurred
  */
  public function setMovementsLinks ($controller, $page) {
    if ($this->movements->count() > 0) {
      $this->movements->each(function($movement) use($controller, $page) {
        if (!is_null($movement->asset) && !$movement->asset->trashed())
          $movement->asset->setUrl($controller, $page);
      });
    }
    return 0;
  }

/**********************************************************************
                       User actions
**********************************************************************/

  /**
  * Modifies a portfolio
  */
  public function beforeValidate () {
    if ($this->close_date == '0000-00-00' || $this->close_date == '')
      $this->close_date = null;
  }
}