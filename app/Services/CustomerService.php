<?php
namespace App\Services;

use App\Events\AfterCustomerAccountHistoryCreatedEvent;
use App\Events\CustomerAfterUpdatedEvent;
use Exception;
use Illuminate\Support\Facades\Auth;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Exceptions\NotFoundException;
use App\Exceptions\NotAllowedException;
use App\Models\CustomerAccountHistory;

class CustomerService
{
    /**
     * get all the defined customers
     * @param array customers
     */
    public function get( $id = null )
    {
        if ( $id === null ) {
            return Customer::with( 'billing' )
                ->with( 'shipping' )
                ->where( 'group_id', '<>', null )
                ->orderBy( 'created_at', 'desc' )->get();
        } else {
            try {
                $customer   =   Customer::find( $id );
                $customer->address;
                return $customer;
            } catch( Exception $exception ) {
                throw new Exception( __( 'Unable to find the customer using the provided id.' ) );
            }
        }
    }

    /**
     * delete a specific customer
     * using a provided id
     * @param int customer id
     * @return array resopnse
     */
    public function delete( $id )
    {
        /**
         * @todo dispatch event while
         * deleting a customer
         * @todo check if the action is performed by
         * an authorized user
         */
        $customer   =   Customer::find( $id );

        if ( ! $customer instanceof Customer ) {
            throw new NotFoundException([
                'status'    =>  'failed',
                'message'   =>  __( 'Unable to find the customer using the provided id.' )
            ]);
        }

        Customer::find( $id )->delete();
        CustomerAddress::where( 'customer_id', $id )->delete();

        return [
            'status'    =>  'success',
            'message'   =>  __( 'The customer has been deleted.' )
        ];
    }

    /**
     * Create customer fields
     * @param array fields
     * @return array response
     */
    public function create( $fields )
    {
        /**
         * Let's find if a similar customer exist with 
         * the provided email
         */
        $customer   =   Customer::byEmail( $fields[ 'email' ] )->first();

        if ( $customer instanceof Customer ) {
            throw new NotAllowedException( sprintf( __( 'The email "%s" is already stored on another customer informations.' ), $fields[ 'email' ] ) );
        }

        /**
         * saving a customer
         * by looping only 
         * the allowed fields
         */
        $customer   =   new Customer;

        foreach( $fields as $field => $value ) {
            if ( $field !== 'address' ) {
                $customer->$field   =   $value;
            }
        }

        $customer->author       =   Auth::id();
        $customer->save();

        /**
         * Let's check if the customer
         * address informations has been provided
         */
        $address                    =   $fields[ 'address' ];

        if ( is_array( $address ) ) {

            foreach( $address as $type => $fields ) {
                if ( in_array( $type, [ 'billing', 'shipping' ] ) ) {

                    $customerAddress                =   new CustomerAddress;
                    $customerAddress->type          =   $type;
                    $customerAddress->author        =   Auth::id();
                    $customerAddress->customer_id   =   $customer->id;

                    foreach( $fields as $field => $value ) {
                        $customerAddress->$field    =   $value;
                    }
                    
                    $customerAddress->save();
                }
            }
        }

        $customer       =   $customer->fresh();
        $customer->addresses;

        return [
            'status'    =>  'success',
            'message'   =>  __( 'The customer has been created.' ),
            'data'      =>  compact( 'customer' )
        ];
    }

    /**
     * Update a specific customer
     * using a provided informations
     * @param int customer id
     * @param array data
     * @return array response
     */
    public function update( $id, array $fields )
    {
        $customer   =   Customer::find( $id );

        if ( ! $customer instanceof Customer ) {
            throw new NotFoundException([
                'status'    =>  'failed',
                'message'   =>  __( 'Unable to find the customer using the provided ID.' )
            ]);
        }

        foreach( $fields as $field => $value ) {
            if ( $field !== 'address' ) {
                $customer->$field       =   $value;
            }
        }

        $customer->author           =   Auth::id();
        $customer->update();

        /**
         * Let's check if the customer
         * address informations has been provided
         */
        $address    =   $fields[ 'address' ];

        if ( is_array( $address ) ) {
            foreach( $address as $type => $addressFields ) {
                if ( in_array( $type, [ 'billing', 'shipping' ] ) ) {

                    $customerAddress            =   CustomerAddress::from( $customer, $type )->first();
                    
                    /**
                     * If the customer address type has 
                     * already been saved before
                     */
                    if ( $customerAddress instanceof CustomerAddress ) {

                        $customerAddress->type          =   $type;
                        $customerAddress->author        =   Auth::id();
                        $customerAddress->customer_id   =   $customer->id; 
    
                        foreach( $addressFields as $field => $value ) {
                            $customerAddress->$field    =   $value;
                        }
        
                        $customerAddress->save();

                    } else {

                        $customerAddress            =   new CustomerAddress;
                        $customerAddress->type      =   $type;
                        $customerAddress->author    =   Auth::id();
                        $customerAddress->customer_id   =   $customer->id;

                        foreach( $addressFields as $field => $value ) {
                            $customerAddress->$field    =   $value;
                        }
        
                        $customerAddress->save();
                    } 
                }
            }
        }
        
        $customer       =   $customer->fresh();
        $customer->addresses;

        return [
            'status'    =>  'success',
            'message'   =>  __( 'The customer has been edited.' ),
            'data'      =>  compact( 'customer' )
        ];
    }

    /**
     * get customers addresses
     * @param int customer id
     * @return array
     */
    public function getCustomerAddresses( $id )
    {
        $customer   =   $this->get( $id );
        return $customer->addresses;
    }

    /**
     * Delete a specific customer
     * who use the provided email
     * @param string email
     * @return array response
     */
    public function deleteUsingEmail( $email )
    {
        $customer       =   Customer::byEmail( $email )->first();

        if ( ! $customer instanceof Customer ) {
            throw new NotFoundException([
                'status'    =>  'failed',
                'message'   =>  __( 'Unable to find the customer using the provided email.' )
            ]);
        }

        CustomerAddress::where( 'customer_id', $customer->id )->delete();
        $customer->delete();

        return [
            'status'    =>  'success',
            'message'   =>  __( 'The customer has been deleted.' )
        ];
    }

    /**
     * save customer transaction
     * @param string operation
     * @param int amount
     * @return array
     */
    public function saveTransaction( Customer $customer, $operation, $amount, $description = '' )
    {
        if ( in_array( $operation, [ CustomerAccountHistory::OPERATION_DEDUCT ]) && $customer->account_amount - $amount < 0 ) {
            throw new NotAllowedException( __( 'The operation will cause negative account for the customer.' ) );
        }

        $customerAccount                =   new CustomerAccountHistory;
        $customerAccount->operation     =   $operation;
        $customerAccount->customer_id   =   $customer->id;
        $customerAccount->amount        =   $amount;
        $customerAccount->description   =   $description;
        $customerAccount->author        =   Auth::id();
        $customerAccount->save();

        event( new AfterCustomerAccountHistoryCreatedEvent( $customerAccount ) );

        return [
            'status'    =>  'success',
            'message'   =>  __( 'The customer account has been updated.' )
        ];
    }

    public function updateCustomerAccount( CustomerAccountHistory $history )
    {
        if ( in_array( $history->operation, [ 
            CustomerAccountHistory::OPERATION_DEDUCT,
            CustomerAccountHistory::OPERATION_PAYMENT,
        ] ) ) {
            $history->customer->account_amount      -=   $history->amount;
        } else if ( in_array( $history->operation, [
            CustomerAccountHistory::OPERATION_ADD,
            CustomerAccountHistory::OPERATION_REFUND,
        ]) ) {
            $history->customer->account_amount      +=   $history->amount;
        } 

        $history->customer->save();
    }

    public function increaseOrderPurchases( Customer $customer, $value )
    {
        $customer->purchases_amount     +=  $value;
        $customer->save();

        event( new CustomerAfterUpdatedEvent( $customer ) );

        return $customer;
    }

    public function canReduceCustomerAccount( Customer $customer, $value )
    {
        if ( $customer->account_amount - $value < 0 ) {
            throw new NotAllowedException( __( 'The customer account doesn\'t have enough funds to proceed.' ) );
        }
    }
}