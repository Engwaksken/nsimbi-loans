<div class="row">

    <div class="col-md-6 mb-3">
        <label class="form-label">Product Code</label>
        <input type="text"
               name="product_code"
               id="edit_product_code"
               class="form-control"
               placeholder="LP-001"
               required>
    </div>

    <div class="col-md-6 mb-3">
        <label class="form-label">Product Name</label>
        <input type="text"
               name="product_name"
               id="edit_product_name"
               class="form-control"
               placeholder="1 Month Business Loan"
               required>
    </div>

    <div class="col-md-12 mb-3">
        <label class="form-label">Description</label>
        <textarea name="description"
                  id="edit_description"
                  class="form-control"
                  rows="3"
                  placeholder="Enter loan product description"></textarea>
    </div>

    <div class="col-md-6 mb-3">
        <label class="form-label">Loan Period (Months)</label>
        <input type="number"
               name="loan_period_months"
               id="edit_loan_period_months"
               class="form-control"
               min="1"
               required>
    </div>

    <div class="col-md-6 mb-3">
        <label class="form-label">Interest Rate (%)</label>
        <input type="number"
               name="interest_rate"
               id="edit_interest_rate"
               class="form-control"
               step="0.01"
               min="0"
               required>
    </div>

    <div class="col-md-6 mb-3">
        <label class="form-label">Minimum Amount</label>
        <input type="number"
               name="minimum_amount"
               id="edit_minimum_amount"
               class="form-control"
               step="0.01"
               min="0"
               value="0">
    </div>

    <div class="col-md-6 mb-3">
        <label class="form-label">Maximum Amount</label>
        <input type="number"
               name="maximum_amount"
               id="edit_maximum_amount"
               class="form-control"
               step="0.01"
               min="0"
               value="0">
    </div>

    <div class="col-md-6 mb-3">
        <label class="form-label">Processing Fee</label>
        <input type="number"
               name="processing_fee"
               id="edit_processing_fee"
               class="form-control"
               step="0.01"
               min="0"
               value="0">
    </div>

    <div class="col-md-6 mb-3">
        <label class="form-label">Penalty Rate (%)</label>
        <input type="number"
               name="penalty_rate"
               id="edit_penalty_rate"
               class="form-control"
               step="0.01"
               min="0"
               value="0">
    </div>

    <div class="col-md-4 mb-3">
        <label class="form-label">Repayment Frequency</label>
        <select name="repayment_frequency"
                id="edit_repayment_frequency"
                class="form-control">

            <option value="daily">Daily</option>
            <option value="weekly">Weekly</option>
            <option value="monthly">Monthly</option>
            <option value="quarterly">Quarterly</option>
            <option value="yearly">Yearly</option>

        </select>
    </div>

    <div class="col-md-4 mb-3">
        <label class="form-label">Interest Type</label>
        <select name="interest_type"
                id="edit_interest_type"
                class="form-control">

            <option value="flat">Flat</option>
            <option value="reducing">Reducing Balance</option>

        </select>
    </div>

    <div class="col-md-4 mb-3">
        <label class="form-label">Status</label>
        <select name="status"
                id="edit_status"
                class="form-control">

            <option value="active">Active</option>
            <option value="inactive">Inactive</option>

        </select>
    </div>

</div>