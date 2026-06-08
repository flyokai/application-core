<?php

namespace Flyokai\SearchCriteria\Compiler\Stage;

use Flyokai\SearchCriteria\Exception\CriteriaDepthException;
use Flyokai\SearchCriteria\Exception\CriteriaException;

/**
 * Transforms external JSON format into a canonical internal format.
 *
 * External: {"AND": [...], "sort": [...], "limit": 10}
 *   or bare: {"AND": [...]}
 *   or full: {"filter": {"AND": [...]}, "sort": [...]}
 *
 * Internal (canonical):
 *   {"filter": {"type": "and", "children": [...]}, "sort": [...], "limit": N, "offset": N, "withTotal": bool}
 *
 * Node normalization:
 *   {"AND": [...]}              -> {"type": "and", "children": [...]}
 *   {"OR": [...]}               -> {"type": "or",  "children": [...]}
 *   {"NOT": {...}}              -> {"type": "not", "child": {...}}
 *   {"field": "x", "op": "eq"} -> {"type": "field", "field": "x", "op": "eq", "value": ...}
 */
class CriteriaJsonNormalizer
{
    public function __construct(
        private int $maxDepth = 16,
        private int $maxNodes = 512,
    ) {}

    public function normalize(array $input): array
    {
        $result = [];

        // If the input has a top-level group key (AND/OR/NOT) without a "filter" wrapper,
        // treat the whole thing as the filter.
        if ($this->isFilterNode($input)) {
            $result['filter'] = $input;
        } else {
            $result['filter'] = $input['filter'] ?? null;
            $result['sort'] = $input['sort'] ?? [];
            $result['limit'] = $input['limit'] ?? null;
            $result['offset'] = $input['offset'] ?? null;
            $result['withTotal'] = $input['withTotal'] ?? false;
        }

        if ($result['filter'] !== null) {
            $nodeCount = 0;
            $result['filter'] = $this->normalizeNode($result['filter'], 0, $nodeCount);
        }

        // Normalize sort entries
        if (!isset($result['sort'])) {
            $result['sort'] = [];
        }
        $result['sort'] = array_map(function (array|string $entry): array {
            if (is_string($entry)) {
                // "+field" or "-field" or "field"
                if (str_starts_with($entry, '-')) {
                    return ['field' => substr($entry, 1), 'direction' => 'desc'];
                }
                if (str_starts_with($entry, '+')) {
                    return ['field' => substr($entry, 1), 'direction' => 'asc'];
                }
                return ['field' => $entry, 'direction' => 'asc'];
            }
            return [
                'field' => $entry['field'],
                'direction' => $entry['direction'] ?? 'asc',
            ];
        }, $result['sort']);

        return $result;
    }

    private function isFilterNode(array $data): bool
    {
        return isset($data['AND']) || isset($data['OR']) || isset($data['NOT']) || isset($data['field']);
    }

    private function normalizeNode(array $node, int $depth, int &$nodeCount): array
    {
        if ($depth > $this->maxDepth) {
            throw new CriteriaDepthException(
                "Criteria tree exceeds maximum depth of {$this->maxDepth}"
            );
        }

        $nodeCount++;
        if ($nodeCount > $this->maxNodes) {
            throw new CriteriaException(
                "Criteria tree exceeds maximum node count of {$this->maxNodes}"
            );
        }

        // Already has a valid type tag (idempotent)
        if (isset($node['type']) && in_array($node['type'], ['and', 'or', 'not', 'field'], true)) {
            if ($node['type'] === 'and' || $node['type'] === 'or') {
                $node['children'] = array_map(
                    fn(array $child) => $this->normalizeNode($child, $depth + 1, $nodeCount),
                    $node['children'] ?? []
                );
            } elseif ($node['type'] === 'not') {
                $node['child'] = $this->normalizeNode($node['child'], $depth + 1, $nodeCount);
            }
            return $node;
        }

        // {"AND": [...]}
        if (isset($node['AND'])) {
            return [
                'type' => 'and',
                'children' => array_map(
                    fn(array $child) => $this->normalizeNode($child, $depth + 1, $nodeCount),
                    $node['AND']
                ),
            ];
        }

        // {"OR": [...]}
        if (isset($node['OR'])) {
            return [
                'type' => 'or',
                'children' => array_map(
                    fn(array $child) => $this->normalizeNode($child, $depth + 1, $nodeCount),
                    $node['OR']
                ),
            ];
        }

        // {"NOT": {...}}
        if (isset($node['NOT'])) {
            return [
                'type' => 'not',
                'child' => $this->normalizeNode($node['NOT'], $depth + 1, $nodeCount),
            ];
        }

        // {"field": "x", "op": "eq", "value": ...}
        if (isset($node['field'])) {
            return [
                'type' => 'field',
                'field' => $node['field'],
                'op' => $node['op'] ?? throw new CriteriaException(
                    "Field predicate for '{$node['field']}' is missing 'op'"
                ),
                'value' => $node['value'] ?? null,
            ];
        }

        throw new CriteriaException('Unrecognized criteria node: ' . json_encode($node));
    }
}
