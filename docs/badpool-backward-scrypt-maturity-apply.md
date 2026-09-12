# Backward Scrypt maturity guarded apply contract

PR #97 established the read-only `badpool.backward_maturity_dryrun.v1` contract. PR #98 established the read-only `badpool.backward_maturity_approval_package.v1` review binding. This change adds only the separately guarded `backward-maturity-transition-apply` contract; it does not apply it, deploy it, or make an approval package an authorization token.

## Fixed review binding

The only eligible scope is coin `1267`, Scrypt `BAD`, 71 earnings (`12623-12662,12696-12726`) linked one-to-one to 71 blocks (`14263-14302,14336-14359,14361-14367`), totaling `153624.877229050000 BAD`. The inventory checksum is `145db645b8ce0b04b55a13ef788b00c0f87b10674bbc9ab2d7e00c250bcd2d2c`. The retained dry-run file SHA-256 is `0102703a6ce840471b87ac8e8324763670615763aaafc11d4a9b3a9504fabfea`. The reviewed package's internal `approval_package_checksum.value` is `acc0955d8b97720f86a18fe7e2308d3007157ab2dcea43c66f143d1099a27b7a`; that value is not the approval package file SHA-256.

`--approval-package-checksum` means the SHA-256 of the exact package file bytes. For the retained PR98 artifact it must be `6cad1443efda2f794c87faa588c62602aae4f2f1e61215427613d643942d8e7b`. The package's `approval_package_checksum` is retained as a historical review binding only: it must be under that exact key, use `sha256`, contain the canonical lowercase reviewed value above, exclude exactly `generated_at` and `approval_package_checksum`, and use the review-only purpose. Because PR98 finalized its JSON after calculating that value, apply does not claim to recompute that historical binding from the finalized retained bytes. Other package shapes still require recomputation.

The internal checksum alone is never authorization. Apply additionally requires the exact retained package file SHA, retained dry-run SHA, exact scope, successful fresh validation, and exact operator confirmation. The transaction boundary and all payout, wallet, account-credit, service, backend-loop, and share-deletion prohibitions remain unchanged.

Apply reports keep pass/fail gates in `retained_package_checks`. Historical-binding facts, including whether recomputation was required, are emitted separately in `retained_package_checksum_diagnostics`; informational values never participate in the mutation gate.

## Mutation boundary and transaction

After exact-byte retained-file validation, package contract validation, exact operator confirmation, and a fresh SELECT-only rebuild, the command starts one transaction and locks the selected earning/block join. Fresh validation must still report 71 status-0 earnings, 71 immature blocks, the exact amount, IDs and retained time ranges, with no assertion failures. Prior-credit detection exclusively uses `earnings.create_time <= accounts.last_earning`; block time is not an acceptable substitute.

The transaction may change only:

* the explicit earnings from `status = 0` to `status = 1`; and
* the explicit linked blocks from `category = immature` to `category = generate`.

It must update exactly 71 rows of each type and verify the post-state before commit. Any precondition drift produces `hold` without mutation. Any update-count, post-state, or runtime mismatch produces `rollback`.

There is no account credit or `accounts.last_earning` update, payout-row creation, wallet read/send, service action, backend-loop execution, or share deletion. The report hashes the operator confirmation and never records its raw text.

## Command shape (non-live example)

The confirmation below is intentionally a shape, not a production operator confirmation. Replace every angle-bracket value only during a separately approved operator action:

```text
php yaamp/yiic.php badpoolguard backward-maturity-transition-apply \
  --coin-id=1267 \
  --approval-package=<reviewed-package-path> \
  --approval-package-checksum=<exact-file-sha256> \
  --dryrun-report=<reviewed-dryrun-path> \
  --dryrun-report-checksum=<exact-file-sha256> \
  --selected-earning-ids=<exact-71-id-csv> \
  --selected-block-ids=<exact-71-id-csv> \
  --expected-inventory-checksum=<reviewed-inventory-sha256> \
  --operator-confirms-backward-maturity-transition=<exact-generated-confirmation-shape> \
  --format=json
```

Production apply requires a separate operator action after merge, deployment, and readiness validation. This design PR and its fixture harness do not authorize or perform that action.

The command is guarded twice: its complete argument set must first pass the command's outer guard context, and the executor then repeats the strict package, retained-report, confirmation, fresh-state, and transaction validation described above. The outer apply route does not broaden the option allowlist used by any other guarded apply command.

The retained PR98 command output contains a top-level `report_checksum` added after `approval_package_checksum.value` was computed. The exact retained file SHA plus the exact, well-formed review-only checksum object identify this one historical artifact; they do not create a bypass for arbitrary packages.
