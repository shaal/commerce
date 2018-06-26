<?php

namespace Drupal\Tests\commerce_promotion\Kernel;

use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_order\Entity\OrderItemType;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_price\Price;
use Drupal\commerce_promotion\Entity\Promotion;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;

/**
 * Tests promotion conditions.
 *
 * @group commerce
 */
class PromotionConditionTest extends CommerceKernelTestBase {

  /**
   * The test order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $order;

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'entity_reference_revisions',
    'profile',
    'state_machine',
    'commerce_order',
    'path',
    'commerce_product',
    'commerce_promotion',
    'commerce_promotion_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->installEntitySchema('profile');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_promotion');
    $this->installEntitySchema('commerce_product_variation');
    $this->installEntitySchema('commerce_product');
    $this->installConfig([
      'profile',
      'commerce_order',
      'commerce_product',
      'commerce_promotion',
    ]);
    $this->installSchema('commerce_promotion', ['commerce_promotion_usage']);

    OrderItemType::create([
      'id' => 'test',
      'label' => 'Test',
      'orderType' => 'default',
    ])->save();

    $this->order = Order::create([
      'type' => 'default',
      'state' => 'completed',
      'mail' => 'test@example.com',
      'ip_address' => '127.0.0.1',
      'order_number' => '6',
      'store_id' => $this->store,
      'order_items' => [],
    ]);
  }

  /**
   * Tests promotions with an order condition.
   */
  public function testOrderCondition() {
    // Starts now, enabled. No end time. Matches orders under $20 or over $100.
    $promotion = Promotion::create([
      'name' => 'Promotion 1',
      'order_types' => [$this->order->bundle()],
      'stores' => [$this->store->id()],
      'status' => TRUE,
      'offer' => [
        'target_plugin_id' => 'order_percentage_off',
        'target_plugin_configuration' => [
          'percentage' => '0.10',
        ],
      ],
      'conditions' => [
        [
          'target_plugin_id' => 'order_total_price',
          'target_plugin_configuration' => [
            'operator' => '<',
            'amount' => [
              'number' => '20.00',
              'currency_code' => 'USD',
            ],
          ],
        ],
        [
          'target_plugin_id' => 'order_total_price',
          'target_plugin_configuration' => [
            'operator' => '>',
            'amount' => [
              'number' => '100.00',
              'currency_code' => 'USD',
            ],
          ],
        ],
      ],
      'condition_operator' => 'OR',
    ]);
    $promotion->save();

    $order_item = OrderItem::create([
      'type' => 'test',
      'quantity' => 3,
      'unit_price' => [
        'number' => '10.00',
        'currency_code' => 'USD',
      ],
    ]);
    $order_item->save();
    $this->order->addItem($order_item);
    $result = $promotion->applies($this->order);
    $this->assertFalse($result);

    $order_item->setQuantity(1);
    $order_item->save();
    $this->order->save();
    $result = $promotion->applies($this->order);
    $this->assertTrue($result);

    $order_item->setQuantity(11);
    $order_item->save();
    $this->order->save();
    $result = $promotion->applies($this->order);
    $this->assertTrue($result);

    // No order total can satisfy both conditions.
    $promotion->setConditionOperator('AND');
    $result = $promotion->applies($this->order);
    $this->assertFalse($result);
  }

  /**
   * Tests promotions with both order and order item conditions.
   */
  public function testMixedCondition() {
    // Starts now, enabled. No end time.
    $promotion = Promotion::create([
      'name' => 'Promotion 1',
      'order_types' => [$this->order->bundle()],
      'stores' => [$this->store->id()],
      'status' => TRUE,
      'offer' => [
        'target_plugin_id' => 'order_percentage_off',
        'target_plugin_configuration' => [
          'percentage' => '0.10',
        ],
      ],
      'conditions' => [
        [
          'target_plugin_id' => 'order_total_price',
          'target_plugin_configuration' => [
            'operator' => '>',
            'amount' => [
              'number' => '30.00',
              'currency_code' => 'USD',
            ],
          ],
        ],
        [
          'target_plugin_id' => 'order_item_quantity',
          'target_plugin_configuration' => [
            'operator' => '>',
            'quantity' => 1,
          ],
        ],
      ],
      'condition_operator' => 'AND',
    ]);
    $promotion->save();

    $order_item = OrderItem::create([
      'type' => 'test',
      'quantity' => 4,
      'unit_price' => [
        'number' => '10.00',
        'currency_code' => 'USD',
      ],
    ]);
    $order_item->save();

    // AND: Both conditions apply.
    $this->order->addItem($order_item);
    $this->order->save();
    $result = $promotion->applies($this->order);
    $this->assertTrue($result);

    // OR: Both conditions apply.
    $promotion->setConditionOperator('OR');
    $result = $promotion->applies($this->order);
    $this->assertTrue($result);

    // AND: Neither condition applies.
    $order_item->setQuantity(1);
    $order_item->save();
    $this->order->save();
    $promotion->setConditionOperator('AND');
    $result = $promotion->applies($this->order);
    $this->assertFalse($result);

    // OR: Neither condition applies.
    $promotion->setConditionOperator('OR');
    $result = $promotion->applies($this->order);
    $this->assertFalse($result);

    // AND: Order condition fails, order item condition passes.
    $order_item->setQuantity(2);
    $order_item->save();
    $this->order->save();
    $promotion->setConditionOperator('AND');
    $result = $promotion->applies($this->order);
    $this->assertFalse($result);

    // OR: Order condition fails, order item condition passes.
    $promotion->setConditionOperator('OR');
    $result = $promotion->applies($this->order);
    $this->assertTrue($result);

    // AND: Order condition passes, order item condition fails.
    $order_item->setUnitPrice(new Price('40', 'USD'));
    $order_item->setQuantity(1);
    $order_item->save();
    $this->order->save();
    $promotion->setConditionOperator('AND');
    $result = $promotion->applies($this->order);
    $this->assertFalse($result);

    // OR: Order condition passes, order item condition fails.
    $promotion->setConditionOperator('OR');
    $result = $promotion->applies($this->order);
    $this->assertTrue($result);
  }

}
