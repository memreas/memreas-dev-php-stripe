<?php
/*
 * Preconfig stripe plan for subscription
 * */

namespace Application\memreas;
use ZfrStripeModule;
use ZfrStripe\Client\StripeClient;
use ZfrStripe\Exception\TransactionErrorException;
use ZfrStripe\Exception\NotFoundException;

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
		$StripePlans = array(
				'PLAN_A_2GB_MONTHLY' 	=> array(
												'plan_id' 		=> 'PLAN_A_2GB_MONTHLY',
												'plan_amount' 	=> 0,
												'plan_name' 	=> 'Plan A: Free 2GB monthly',								
											),
				'PLAN_B_10GB_MONTHLY'	=> array(
												'plan_id' 		=> 'PLAN_B_10GB_MONTHLY',
												'plan_amount' 	=> 2.5,
												'plan_name' 	=> 'Plan B: 10GB monthly',
											),
				'PLAN_C_50GB_MONTHLY'	=> array(
												'plan_id'		=> 'PLAN_C_50GB_MONTHLY',
												'plan_amount'	=> 4.95,
												'plan_name'		=> 'Plan C: 50GB monthly',		
											),
				'PLAN_D_100GB_MONTHLY'	=> array(
												'plan_id'		=> 'PLAN_D_100GB_MONTHLY',
												'plan_amount'	=> 9.95,
												'plan_name'		=> 'Plan D: 100GB monthly',
											),
		);
		return $StripePlans;
	}  
}

