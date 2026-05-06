<style>
	.accounting-card-link {
		display: block;
		transition: transform 0.18s ease, box-shadow 0.18s ease, opacity 0.18s ease;
		border-radius: 1rem;
	}

	.accounting-card-link:hover {
		transform: translateY(-2px);
		box-shadow: 0 0.75rem 1.75rem rgba(15, 23, 42, 0.12);
	}

	.accounting-card-link:focus-visible {
		outline: 3px solid rgba(59, 130, 246, 0.45);
		outline-offset: 3px;
	}

	.accounting-panel {
		border: 1px solid rgba(15, 23, 42, 0.08);
		box-shadow: 0 0.5rem 1.25rem rgba(15, 23, 42, 0.04);
		border-radius: 1rem;
		overflow: hidden;
	}

	.accounting-panel .card-header {
		background: linear-gradient(135deg, rgba(248, 250, 252, 1), rgba(241, 245, 249, 1));
		border-bottom: 1px solid rgba(15, 23, 42, 0.08);
	}

	.accounting-kpi {
		min-height: 110px;
		border: 0;
		border-radius: 1rem;
		box-shadow: 0 0.45rem 1.2rem rgba(15, 23, 42, 0.08);
	}

	.accounting-kpi h5 {
		font-size: 0.82rem;
		text-transform: uppercase;
		letter-spacing: 0.08em;
		opacity: 0.92;
	}

	.accounting-kpi h3 {
		font-size: 2rem;
		font-weight: 700;
		margin-bottom: 0.1rem;
	}

	.accounting-kpi small {
		opacity: 0.88;
	}

	.accounting-filter-pill {
		display: inline-flex;
		align-items: center;
		gap: 0.4rem;
		padding: 0.3rem 0.75rem;
		border-radius: 999px;
		background: #e2e8f0;
		color: #0f172a;
		font-size: 0.85rem;
		font-weight: 600;
	}

	.accounting-link-table a {
		color: inherit;
	}

	.accounting-link-table a:hover {
		text-decoration: underline;
	}

	.accounting-row-asset          { background: rgba(219, 234, 254, 0.45); }
	.accounting-row-liability      { background: rgba(254, 243, 199, 0.45); }
	.accounting-row-equity         { background: rgba(237, 233, 254, 0.45); }
	.accounting-row-revenue        { background: rgba(220, 252, 231, 0.45); }
	.accounting-row-expense        { background: rgba(254, 226, 226, 0.45); }
	.accounting-row-cost_of_sales  { background: rgba(226, 232, 240, 0.45); }
	.accounting-row-inactive       { opacity: 0.72; }

	.accounting-entry-bill    { background: rgba(219, 234, 254, 0.42); }
	.accounting-entry-payment { background: rgba(220, 252, 231, 0.42); }
	.accounting-entry-manual  { background: rgba(241, 245, 249, 0.55); }
	.accounting-entry-other   { background: rgba(254, 243, 199, 0.35); }

	.accounting-modal-bill    .modal-header { background: linear-gradient(135deg, #1d4ed8, #2563eb) !important; }
	.accounting-modal-payment .modal-header { background: linear-gradient(135deg, #15803d, #16a34a) !important; }
	.accounting-modal-manual  .modal-header { background: linear-gradient(135deg, #334155, #475569) !important; }
	.accounting-modal-other   .modal-header { background: linear-gradient(135deg, #b45309, #d97706) !important; }

	.accounting-entry-line-bill    { background: rgba(219, 234, 254, 0.35); border-color: rgba(59, 130, 246, 0.18); }
	.accounting-entry-line-payment { background: rgba(220, 252, 231, 0.35); border-color: rgba(34, 197, 94, 0.18); }
	.accounting-entry-line-manual  { background: rgba(241, 245, 249, 0.7);  border-color: rgba(100, 116, 139, 0.18); }
	.accounting-entry-line-other   { background: rgba(254, 243, 199, 0.35); border-color: rgba(245, 158, 11, 0.18); }

	.accounting-muted-box {
		border: 1px solid rgba(15, 23, 42, 0.08);
		border-radius: 0.85rem;
		background: #f8fafc;
	}

	.accounting-box-code           { background: linear-gradient(135deg, #dbeafe, #bfdbfe); border-color: #93c5fd; }
	.accounting-box-type-asset     { background: linear-gradient(135deg, #dbeafe, #bfdbfe); border-color: #93c5fd; }
	.accounting-box-type-liability { background: linear-gradient(135deg, #fef3c7, #fde68a); border-color: #fbbf24; }
	.accounting-box-type-equity    { background: linear-gradient(135deg, #ede9fe, #ddd6fe); border-color: #a78bfa; }
	.accounting-box-type-revenue   { background: linear-gradient(135deg, #dcfce7, #bbf7d0); border-color: #86efac; }
	.accounting-box-type-expense   { background: linear-gradient(135deg, #fee2e2, #fecaca); border-color: #fca5a5; }
	.accounting-box-type-cost_of_sales { background: linear-gradient(135deg, #e2e8f0, #cbd5e1); border-color: #94a3b8; }

	.accounting-box-balance-debit  { background: linear-gradient(135deg, #ecfccb, #d9f99d); border-color: #a3e635; }
	.accounting-box-balance-credit { background: linear-gradient(135deg, #cffafe, #a5f3fc); border-color: #67e8f9; }

	.accounting-box-status-active   { background: linear-gradient(135deg, #dcfce7, #bbf7d0); border-color: #86efac; }
	.accounting-box-status-inactive { background: linear-gradient(135deg, #f1f5f9, #e2e8f0); border-color: #cbd5e1; }

	.accounting-entry-line {
		border: 1px solid rgba(15, 23, 42, 0.08);
		border-radius: 0.75rem;
		background: #ffffff;
		padding: 0.8rem;
		height: 100%;
	}

	.accounting-entry-line strong { font-size: 0.9rem; }

	.accounting-amount-positive { color: #166534; font-weight: 600; }
	.accounting-amount-negative { color: #b91c1c; font-weight: 600; }
</style>
