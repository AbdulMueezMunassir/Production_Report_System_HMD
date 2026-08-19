// assets/js/app.js
document.addEventListener('DOMContentLoaded', function() {
    // Live recalculation on input change
    document.querySelectorAll('.calc-input').forEach(input => {
        input.addEventListener('input', recalcRow);
    });
    document.querySelectorAll('.hour-input').forEach(input => {
        input.addEventListener('input', recalcRow);
    });
});

function recalcRow(e) {
    var row = e.target.closest('tr');
    if (!row) return;
    
    // Gather inputs
    var ttlSamPc = parseFloat(row.querySelector('[data-field="ttl_sam_pc"]')?.value) || 0;
    var unitSmv = parseFloat(row.querySelector('[data-field="unit_smv"]')?.value) || 0;
    var unitCarder = parseFloat(row.querySelector('[data-field="unit_carder"]')?.value) || 0;
    var planHours = parseFloat(row.querySelector('[data-field="plan_hours"]')?.value) || 0;
    var workedHours = parseFloat(row.querySelector('[data-field="worked_hours"]')?.value) || 0;
    
    // Get working hours from meta
    var hoursMeta = document.querySelector('.report-meta span:last-child');
    var workingHours = parseInt(hoursMeta.textContent.replace('REPORTING HOURS:', '').trim()) || 10;
    var targetEfficiency = 0.90;
    
    // Calculate
    var dayForecast = (unitCarder * workingHours * 60 * targetEfficiency) / (unitSmv || 1);
    var availMin = unitCarder * planHours * 60;
    var planMin = dayForecast * unitSmv;
    var planEff = availMin > 0 ? (planMin / availMin) * 100 : 0;
    var target100 = unitSmv > 0 ? (unitCarder / unitSmv) * 60 : 0;
    
    // Find calculated cells
    var tds = row.querySelectorAll('td');
    var dayTotal = 0;
    
    // Update calculated cells (positions 9-12)
    if (tds.length > 9) tds[9].textContent = dayForecast.toFixed(2);
    if (tds.length > 10) tds[10].textContent = availMin.toFixed(0);
    if (tds.length > 11) tds[11].textContent = planMin.toFixed(2);
    if (tds.length > 12) tds[12].textContent = planEff.toFixed(2) + '%';
    if (tds.length > 13) tds[13].textContent = target100.toFixed(2);
    
    // Sum hours
    var hourInputs = row.querySelectorAll('.hour-input');
    hourInputs.forEach(input => {
        dayTotal += parseFloat(input.value) || 0;
    });
    
    // Update Day Total and Earned Minutes
    var ernMin = dayTotal * unitSmv;
    var acvdEff = (unitCarder * workedHours * 60) > 0 ? (ernMin / (unitCarder * workedHours * 60)) * 100 : 0;
    
    // Update last 3 columns (Day Ttl, Ern Minutes, Acvd Eff)
    if (tds.length > 0) tds[tds.length - 3].textContent = dayTotal.toFixed(2);
    if (tds.length > 0) tds[tds.length - 2].textContent = ernMin.toFixed(2);
    if (tds.length > 0) tds[tds.length - 1].textContent = acvdEff.toFixed(2) + '%';
}