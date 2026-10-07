<?php

declare(strict_types=1);

namespace Tests\Feature\EntryOrders;

/**
 * Incidents are what an entry order has to settle with the provider. They never block the order
 * nor change its status, and they close only with a note of what was agreed.
 */
class EntryOrderIncidentTest extends EntryOrderTestCase
{
    public function test_an_incident_is_resolved_once_and_only_with_a_note(): void
    {
        $order = $this->excessOrder();
        $incidentId = $order['incidents'][0]['id'];

        $this->apiAs('POST', "/entry-orders/{$order['id']}/incidents/{$incidentId}/resolve", ['resolution' => '  '])
            ->assertStatus(422)
            ->assertJsonPath('code', 'REASON_REQUIRED');

        $resolved = $this->apiAs('POST', "/entry-orders/{$order['id']}/incidents/{$incidentId}/resolve", ['resolution' => 'Se pagan las 2 de más'])
            ->assertOk()->json();

        $this->assertSame('RESOLVED', $resolved['incidents'][0]['status']);
        $this->assertSame('Se pagan las 2 de más', $resolved['incidents'][0]['resolution']);
        $this->assertSame($this->user->id, $resolved['incidents'][0]['resolved_by']['id']);
        $this->assertSame(0, $resolved['open_incidents_count']);
        $this->assertSame('IN_TRANSIT', $resolved['status']);
        $this->assertSame('EXCESS_HEAD', end($resolved['history'])['metadata']['incident_resolved']);

        $this->apiAs('POST', "/entry-orders/{$order['id']}/incidents/{$incidentId}/resolve", ['resolution' => 'Otra vez'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INCIDENT_ALREADY_RESOLVED');
    }

    public function test_a_completed_order_can_keep_an_open_incident(): void
    {
        $order = $this->excessOrder();

        $done = $this->receiveOn($order, $this->animals('IC', 3));

        $this->assertSame('COMPLETED', $done['status']);
        $this->assertSame(1, $done['open_incidents_count']);
    }

    public function test_the_list_filters_the_orders_with_open_incidents(): void
    {
        $withIncident = $this->excessOrder();
        $clean = $this->createOrder(['auction_number' => '777'])->json('order');

        $ids = array_column($this->apiAs('GET', '/entry-orders?incidents=open')->assertOk()->json(), 'id');

        $this->assertContains($withIncident['id'], $ids);
        $this->assertNotContains($clean['id'], $ids);

        $row = collect($this->apiAs('GET', '/entry-orders')->json())->firstWhere('id', $withIncident['id']);
        $this->assertSame(1, $row['open_incidents_count']);
        $this->assertSame(3, $row['in_transit_count']);
    }

    /**
     * An order for 1 head whose DTE brought 3: in transit, with an EXCESS_HEAD incident open.
     *
     * @return array<string, mixed>
     */
    private function excessOrder(): array
    {
        $order = $this->createOrder(['head_count' => 1, 'sex_composition' => 'MALE', 'male_count' => null, 'female_count' => null])->json('order');

        return $this->loadDte($order['id'], 3);
    }
}
