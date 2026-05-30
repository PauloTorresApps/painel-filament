<?php

namespace App\Pipeline\Graph\Nodes;

use App\Pipeline\Graph\Contracts\ExternalNode;
use App\Pipeline\Graph\GraphState;
use App\Pipeline\Graph\NodeResult;
use Illuminate\Support\Facades\Http;

final class HttpNode implements ExternalNode
{
    public function __construct(
        private readonly string $nodeId,
        private readonly string $url,
        private readonly string $method = 'POST',
        private readonly ?string $nextNodeId = null,
        private readonly int $timeoutSeconds = 30,
        private readonly int $retries = 2,
    ) {
    }

    public function id(): string
    {
        return $this->nodeId;
    }

    public function endpoint(): string
    {
        return $this->url;
    }

    public function run(GraphState $state): NodeResult
    {
        $traceparent = (string) $state->get('traceparent', '');
        $request = Http::timeout($this->timeoutSeconds)
            ->retry($this->retries, 200)
            ->acceptJson();

        if ($traceparent !== '') {
            $request = $request->withHeader('traceparent', $traceparent);
        }

        $payload = [
            'node_id' => $this->id(),
            'graph_run_id' => $state->get('graph_run_id'),
            'analysis_id' => $state->get('analysis_id'),
            'state' => $state->all(),
        ];

        $response = strtoupper($this->method) === 'GET'
            ? $request->get($this->url, $payload)
            : $request->post($this->url, $payload);

        $response->throw();

        $responseData = $response->json();

        $updates = [];
        if (is_array($responseData)) {
            $updates['external_response'] = $responseData;
        }

        return NodeResult::continue($this->nextNodeId, $updates);
    }
}
