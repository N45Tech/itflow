<?php

/*
 * N45 migration n45-0000-namespace-foundation
 * Included by the N45 migration runner - do not access directly
 */

defined('FROM_N45_DB_UPDATER') || die("Direct file access is not allowed");

// The runner creates and fingerprints the ledger before recording this no-op
// migration. Its durable row distinguishes an adopted N45 namespace from a
// legacy fork marker when future upstream releases reuse the same version.
