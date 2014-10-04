<?php
/*
 * Preconfig stripe plan for subscription
 * */

namespace Application\memreas;
use ZfrStripeModule;
use ZfrStripe\Client\StripeClient;
use ZfrStripe\Exception\TransactionErrorException;
use ZfrStripe\Exception\NotFoundException;
use Application\Model\MemreasConstants;

class StripePlansConfig{
	
	protected $stripeClient;
	
	public function __construct($stripeClient){		
		$this->stripeClient = $stripeClient;
		$this->verifyStripePlans();
	}	
	
	public function createPlan($data){
		$params = array(
						'id' 		=> $data['plan_id'],
						'amount' 	=> (int)(($data['plan_amount']) * 100),
						'currency' 	=> 'USD',
						'interval' 	=> 'month', //Week , month or year,
						'name'		=> $data['plan_name'],
						);		
		if (isset($data['plan_period'])) $params['trial_period_days'] = $data['plan_period'];
		if (isset($data['metadata'])) $params['metadata'] = $data['metadata'];
		if (isset($data['description'])) $params['statement_description'] = $data['description'];
		
		return $this->stripeClient->createPlan($params);				
	}
	
	public function getPlan($planId){
		try{
			$plan = $this->stripeClient->getPlan(array('id' => $planId));
			return array('plan' => $plan);
		}
		catch(NotFoundException $e){
			return array('plan' => null, 'message' => $e->getMessage());
		}
	}

    public function getPlanConfig($planId){
        $plans = $this->preConfigPlans();
        return $plans[$planId];
    }

    public function getPlanLevel($planId){
        $plans = $this->preConfigPlans();
        return $plans[$planId]['level'];
    }
	
	public function getAllPlans(){
		$stripePlans = $this->stripeClient->getPlans();
		return $stripePlans['data'];			
	}
	
	public function deletePlan($planId){
		try{
			$deletePlan = $this->stripeClient->deletePlan(array('id' => $planId));
			return array('deleted' => true);
		}catch(NotFoundException $e){
			return array('deleted' => false, 'message' => $e->getMessage());
		}
	}
	
	private function verifyStripePlans(){
		$stripePlans = $this->preConfigPlans();
		if (!empty($stripePlans)){
			foreach ($stripePlans as $plan){
				$stripeCheckPlan = $this->getPlan($plan['plan_id']);
				if (empty($stripeCheckPlan['plan'])){
					$this->createPlan($plan);
				}
			}
		}	
	}
	
	private function preConfigPlans(){
        $MemreasConstants = new MemreasConstants();
		$StripePlans = array(
				$MemreasConstants::PLAN_ID_A 	=> array(
												'plan_id' 		=> $MemreasConstants::PLAN_ID_A,
												'plan_amount' 	=> $MemreasConstants::PLAN_AMOUNT_A,
												'plan_name' 	=> $MemreasConstants::PLAN_DETAILS_A,
                                                'storage'       => $MemreasConstants::PLAN_GB_STORAGE_AMOUNT_A,
                                                'level'         => 1
											),
                $MemreasConstants::PLAN_ID_B	=> array(
												'plan_id' 		=> $MemreasConstants::PLAN_ID_B,
												'plan_amount' 	=> $MemreasConstants::PLAN_AMOUNT_B,
												'plan_name' 	=> $MemreasConstants::PLAN_DETAILS_B,
                                                'storage'       => $MemreasConstants::PLAN_GB_STORAGE_AMOUNT_B,
                                                'level'         => 2
											),
                $MemreasConstants::PLAN_ID_C	=> array(
												'plan_id'		=> $MemreasConstants::PLAN_ID_C,
												'plan_amount'	=> $MemreasConstants::PLAN_AMOUNT_C,
												'plan_name'		=> $MemreasConstants::PLAN_DETAILS_C,
                                                'storage'       => $MemreasConstants::PLAN_GB_STORAGE_AMOUNT_C,
                                                'level'         => 3
											),
                $MemreasConstants::PLAN_ID_D	=> array(
												'plan_id'		=> $MemreasConstants::PLAN_ID_D,
												'plan_amount'	=> $MemreasConstants::PLAN_AMOUNT_D,
												'plan_name'		=> $MemreasConstants::PLAN_DETAILS_D,
                                                'storage'       => $MemreasConstants::PLAN_GB_STORAGE_AMOUNT_D,
                                                'level'         => 4
											)
		);
		return $StripePlans;
	}  
}

