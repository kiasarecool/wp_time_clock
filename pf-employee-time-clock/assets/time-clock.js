(function($){
// Kiosk self-healing: periodically reload an idle kiosk so WordPress nonces,
// browser state, and scripts do not sit stale overnight.
var PF_KIOSK_REFRESH_MS = 60 * 60 * 1000;
var pfKioskLoadedAt = Date.now();
var pfKioskRefreshPending = false;
function isKioskPage(){ return $('#pf-tc-employee').length > 0; }
function kioskIsIdle(){
  if(!isKioskPage()) return false;
  return !$('#pf-tc-employee').val() && $('#pf-correction-form').children().length === 0;
}
function refreshKioskWhenSafe(){
  if(!isKioskPage()) return;
  if(kioskIsIdle()) window.location.reload();
  else pfKioskRefreshPending = true;
}
function kioskReturnedIdle(){
  if(pfKioskRefreshPending && kioskIsIdle()) window.location.reload();
}
function armKioskMaintenance(){
  if(!isKioskPage()) return;
  window.setInterval(function(){
    if(Date.now() - pfKioskLoadedAt >= PF_KIOSK_REFRESH_MS) refreshKioskWhenSafe();
  }, 60 * 1000);
  document.addEventListener('visibilitychange', function(){
    if(document.visibilityState === 'visible' && Date.now() - pfKioskLoadedAt >= PF_KIOSK_REFRESH_MS){
      refreshKioskWhenSafe();
    }
  });
  window.addEventListener('pageshow', function(){
    if(Date.now() - pfKioskLoadedAt >= PF_KIOSK_REFRESH_MS) refreshKioskWhenSafe();
  });
}
$(armKioskMaintenance);
function post(action,data,cb){data=data||{};data.action=action;data.nonce=PFTC.nonce;$.post(PFTC.ajax,data).done(function(r){if(r.success)cb&&cb(r.data);else alert((r.data&&r.data.message)||'Something went wrong.');}).fail(function(){alert('Request failed. Please try again.');});}
function pinPrompt(){var p=prompt('Enter your 4-digit PIN:');return p===null?null:p.replace(/\D/g,'').slice(0,4);}
function kioskSuccess(message){
  $('#pf-tc-message').html('<div class="pf-success">✓ '+message+'</div>');
  $('#pf-tc-state,#pf-tc-actions').addClass('pf-hidden');
  setTimeout(function(){
    $('#pf-tc-employee').val('');
    $('#pf-tc-state,#pf-tc-actions').addClass('pf-hidden').empty();
    $('#pf-tc-message').empty();
    $('#pf-tc-employee').trigger('focus');
    kioskReturnedIdle();
  },3000);
}
function correctionButtons(d){
  var edit=d.last_id?'<button class="pf-secondary" id="pf-edit-last" data-date="'+d.last_date+'" data-time="'+d.last_clock+'" data-type="'+d.last_type+'">Edit Last Punch</button>':'';
  return '<div class="pf-correction-actions">'+edit+'<button class="pf-secondary" id="pf-add-punch">Add Missing Punch</button></div><div id="pf-correction-form"></div>';
}
function loadState(){var id=$('#pf-tc-employee').val();if(!id){$('#pf-tc-state,#pf-tc-actions').addClass('pf-hidden');return;}post('pf_tc_state',{employee_id:id},function(d){var s=$('#pf-tc-state').removeClass('pf-hidden'),a=$('#pf-tc-actions').removeClass('pf-hidden').empty();if(d.state==='missing'){s.html('<h3>'+d.name+'</h3><div class="pf-bad">⚠ Missing Clock Out</div><p>You clocked in '+d.last_time+' but did not clock out.</p>');a.html('<label class="pf-label">What time did you leave?</label><input id="pf-manual-time" type="time"><button class="pf-btn" id="pf-submit-manual">Submit Missing Clock Out</button>'+correctionButtons(d));return;}var label=d.state==='in'?'CLOCKED IN':'CLOCKED OUT';s.html('<h3>'+d.name+'</h3><div class="pf-status">Currently '+label+'</div>'+(d.last_time?'<p>Last punch: '+d.last_time+'</p>':''));var type=d.state==='in'?'out':'in';a.html('<button class="pf-btn pf-big" id="pf-punch" data-type="'+type+'">CLOCK '+type.toUpperCase()+'</button>'+correctionButtons(d));});}
$(document).on('change','#pf-tc-employee',function(){loadState(); if(!$(this).val()) kioskReturnedIdle();});
$(document).on('click','#pf-punch',function(){var pin=pinPrompt();if(pin===null)return;var id=$('#pf-tc-employee').val(),type=$(this).data('type');if(!confirm('Confirm CLOCK '+String(type).toUpperCase()+'?'))return;post('pf_tc_punch',{employee_id:id,type:type,pin:pin},function(d){kioskSuccess(d.message);});});
$(document).on('click','#pf-submit-manual',function(){var t=$('#pf-manual-time').val();if(!t){alert('Enter the time you left.');return;}var pin=pinPrompt();if(pin===null)return;post('pf_tc_manual_out',{employee_id:$('#pf-tc-employee').val(),time:t,pin:pin},function(d){kioskSuccess(d.message);});});
$(document).on('click','#pf-edit-last',function(){var date=$(this).data('date'),time=$(this).data('time'),type=String($(this).data('type')).toUpperCase();$('#pf-correction-form').html('<div class="pf-inline-form"><h4>Edit Last Punch ('+type+')</h4><label>Date <input id="pf-edit-date" type="date" value="'+date+'"></label><label>Time <input id="pf-edit-time" type="time" value="'+time+'"></label><button class="pf-btn" id="pf-submit-edit-last">Submit for Review</button><button class="pf-secondary pf-cancel-correction">Cancel</button><p class="pf-muted">This change will not be final until management reviews it.</p></div>');});
$(document).on('click','#pf-submit-edit-last',function(){var date=$('#pf-edit-date').val(),time=$('#pf-edit-time').val();if(!date||!time){alert('Choose a date and time.');return;}var pin=pinPrompt();if(pin===null)return;post('pf_tc_employee_edit_last',{employee_id:$('#pf-tc-employee').val(),date:date,time:time,pin:pin},function(d){kioskSuccess(d.message);});});
$(document).on('click','#pf-add-punch',function(){var today=new Date(),y=today.getFullYear(),m=String(today.getMonth()+1).padStart(2,'0'),day=String(today.getDate()).padStart(2,'0');$('#pf-correction-form').html('<div class="pf-inline-form"><h4>Add Missing Punch</h4><label>Type <select id="pf-add-type"><option value="in">Clock In</option><option value="out">Clock Out</option></select></label><label>Date <input id="pf-add-date" type="date" value="'+y+'-'+m+'-'+day+'"></label><label>Time <input id="pf-add-time" type="time"></label><button class="pf-btn" id="pf-submit-add-punch">Submit for Review</button><button class="pf-secondary pf-cancel-correction">Cancel</button><p class="pf-muted">Manually added punches require management review before report totals can be generated.</p></div>');});
$(document).on('click','#pf-submit-add-punch',function(){var type=$('#pf-add-type').val(),date=$('#pf-add-date').val(),time=$('#pf-add-time').val();if(!date||!time){alert('Choose a date and time.');return;}var pin=pinPrompt();if(pin===null)return;post('pf_tc_employee_add_punch',{employee_id:$('#pf-tc-employee').val(),type:type,date:date,time:time,pin:pin},function(d){kioskSuccess(d.message);});});
$(document).on('click','.pf-cancel-correction',function(){$('#pf-correction-form').empty(); kioskReturnedIdle();});
function runReport(){var s=$('#pf-start').val(),e=$('#pf-end').val();if(!s||!e){alert('Choose a start and end date.');return;}$('#pf-report').html('<p>Loading…</p>');post('pf_tc_report',{start:s,end:e,employee_id:$('#pf-report-employee').val()||0},function(d){$('#pf-report').html(d.html);});}
$(document).on('click','#pf-run-report',runReport);
$(document).on('click','#pf-admin-add-missing',function(){var id=parseInt($('#pf-report-employee').val()||0,10),url=$(this).attr('href').split('&employee_id=')[0];if(id>0)$(this).attr('href',url+'&employee_id='+id);else $(this).attr('href',url);});
$(document).on('click','.pf-review',function(){post('pf_tc_review',{punch_id:$(this).data('id'),mode:'accept'},runReport);});
$(document).on('click','.pf-edit-review',function(){var t=prompt('Correct punch time (24-hour HH:MM):',$(this).data('time'));if(!t)return;var note=prompt('Reason / note (optional):','');post('pf_tc_review',{punch_id:$(this).data('id'),mode:'edit',time:t,note:note||''},runReport);});
$(document).on('click','.pf-manager-add-out',function(){var t=prompt('Missing clock-out time (24-hour HH:MM):','');if(!t)return;var note=prompt('Reason / note (optional):','');post('pf_tc_manager_add_missing_out',{in_id:$(this).data('in-id'),time:t,note:note||''},runReport);});
$(document).on('click','.pf-ignore-unmatched',function(){var b=$(this);if(!confirm('Ignore this unmatched clock-in? Use this when the clock-in itself was accidental or a duplicate. It will stay in the audit history but will not affect clock state, reports, totals, or exports.'))return;var note=prompt('Reason for ignoring this record (optional):','');post('pf_tc_manager_ignore_unmatched',{in_id:b.data('in-id'),note:note||''},runReport);});
$(document).on('click','.pf-edit-pair',function(){var b=$(this),it=prompt('Clock-in time (24-hour HH:MM):',b.data('in-time'));if(!it)return;var ot=prompt('Clock-out time (24-hour HH:MM):',b.data('out-time'));if(!ot)return;var note=prompt('Reason / note (optional):','');post('pf_tc_manager_pair_action',{mode:'edit',in_id:b.data('in-id'),out_id:b.data('out-id'),in_time:it,out_time:ot,note:note||''},runReport);});
$(document).on('click','.pf-ignore-pair',function(){var b=$(this);if(!confirm('Ignore this entire IN/OUT record? It will be removed from totals and exports, but preserved in the audit history.'))return;var note=prompt('Reason for ignoring this record (optional):','');post('pf_tc_manager_pair_action',{mode:'ignore',in_id:b.data('in-id'),out_id:b.data('out-id'),note:note||''},runReport);});
$(document).on('click','.pf-details',function(){var b=$(this);post('pf_tc_details',{punch_id:b.data('id'),related_id:b.data('related-id')||0},function(d){$('#pf-detail-modal').remove();$('body').append('<div id="pf-detail-modal" class="pf-detail-overlay"><div class="pf-detail-modal">'+d.html+'</div></div>');});});
$(document).on('click','.pf-detail-close',function(){$('#pf-detail-modal').remove();});
$(document).on('click','.pf-detail-overlay',function(e){if(e.target===this)$(this).remove();});
$(document).on('keydown',function(e){if(e.key==='Escape')$('#pf-detail-modal').remove();});
})(jQuery);
