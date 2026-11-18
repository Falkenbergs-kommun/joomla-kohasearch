#!/usr/bin/env python3
"""Verifieringsverktyg för BM25 sparse query embedding.

Används enbart för att verifiera att PHP-implementationen i search.php
ger samma resultat som fastembed Qdrant/bm25.

Användning:
    .venv/bin/python3 sparse_embed.py "sökfråga"
"""
import json
import sys

from fastembed import SparseTextEmbedding

model = SparseTextEmbedding(model_name="Qdrant/bm25")

query = sys.argv[1] if len(sys.argv) > 1 else "vikingatiden"
for embedding in model.query_embed(query):
    print(json.dumps({
        "indices": sorted(embedding.indices.tolist()),
        "values": embedding.values.tolist()[:3],
    }))
