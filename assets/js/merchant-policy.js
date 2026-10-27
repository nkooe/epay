(function(){
  'use strict';
  const root=document.getElementById('merchant-policy');if(!root)return;
  const el=id=>document.getElementById('mp-'+id),form=el('form'),rules=el('rules'),mode=el('mode'),error=el('error'),status=el('status');
  let state=null,busy=false;
  function showError(message){error.textContent=message;error.hidden=!message;el('retry').hidden=!message||!!state;}
  function lock(value){busy=value;form.querySelectorAll('input,select,button').forEach(n=>n.disabled=value);modeChanged();el('save').textContent=value?'正在保存…':'保存策略';}
  function node(tag,text,attrs={}){const n=document.createElement(tag);if(text!==undefined)n.textContent=text;Object.keys(attrs).forEach(k=>n.setAttribute(k,attrs[k]));return n;}
  async function request(data){const response=await fetch('/admin/merchant-policy-api.php'+(data?'':'?uid='+encodeURIComponent(root.dataset.uid)),{method:data?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:data?{'Content-Type':'application/json','X-CSRF-Token':root.dataset.csrf}:{},body:data?JSON.stringify(data):undefined});let body;try{body=await response.json();}catch(_){throw Error('服务器响应异常，请重新读取');}if(!response.ok||body.code!==0)throw Error(body.msg||'请求失败，请重试');return body.data;}
  function modeChanged(){const custom=mode.value==='custom';rules.hidden=!custom;rules.querySelectorAll('section').forEach(section=>{const toggle=section.querySelector('[type=checkbox]');toggle.disabled=busy||!custom;section.querySelectorAll('select,input[type=number]').forEach(n=>n.disabled=busy||!custom||!toggle.checked);section.querySelector('select').required=custom&&toggle.checked;});el('mode-hint').textContent=custom?'仅启用下列勾选的支付方式。指定通道不可用时直接报错，不切换通道；0 表示不加限制，通道及系统限额仍生效。':'按商户所属用户组的通道、轮询和费率配置处理。';}
  function render(data){
    state=data;mode.value=data.mode;rules.replaceChildren();el('merchant').textContent='商户 #'+data.uid+' · '+data.username;
    data.types.forEach(type=>{
      const rule=data.rules.find(r=>Number(r.type_id)===Number(type.id)),section=node('section',undefined,{class:'mp-rule','data-type':type.id});
      const heading=node('div',undefined,{class:'mp-rule-heading'}),title=node('h3',(type.showname||type.name)+(Number(type.status)!==1?'（已停用）':''));
      const toggle=node('input',undefined,{type:'checkbox','aria-label':'启用'+(type.showname||type.name)});toggle.checked=!!rule;
      const label=node('label');label.append(toggle,document.createTextNode('启用'));heading.append(title,label);section.append(heading);
      const fields=node('div',undefined,{class:'mp-fields'}),select=node('select',undefined,{id:'mp-channel-'+type.id,'data-key':'channel',required:''});select.append(node('option','请选择通道',{value:''}));
      const available=data.channels.filter(c=>Number(c.type_id)===Number(type.id));
      available.forEach(c=>select.append(node('option',c.name+' #'+c.channel_id+(c.subchannel_id?' / 子通道 #'+c.subchannel_id:'')+(!c.enabled?'（不可用）':''),{value:c.channel_id+':'+c.subchannel_id})));
      if(rule){const key=rule.channel_id+':'+rule.subchannel_id;if(!available.some(c=>c.channel_id+':'+c.subchannel_id===key))select.append(node('option','已删除的指定通道 #'+rule.channel_id,{value:key}));select.value=key;}
      const channelField=node('div',undefined,{class:'mp-field'});channelField.append(node('label','指定通道',{for:select.id}),select);fields.append(channelField);
      ['min','max'].forEach(key=>{const field=node('div',undefined,{class:'mp-field'}),input=node('input',undefined,{type:'number',min:'0',max:'99999999.99',step:'0.01',inputmode:'decimal',id:'mp-'+key+'-'+type.id,'data-key':key});input.value=rule?rule[key]:'0.00';field.append(node('label',key==='min'?'单笔最低（元）':'单笔最高（元）',{for:input.id}),input);fields.append(field);});
      const update=()=>{fields.hidden=!toggle.checked;select.required=toggle.checked;modeChanged();};toggle.addEventListener('change',update);update();section.append(fields);rules.append(section);
    });
    modeChanged();form.hidden=false;
  }
  async function load(){if(busy)return;showError('');status.textContent='';lock(true);try{render(await request());}catch(e){showError(e.message);}finally{lock(false);}}
  mode.addEventListener('change',modeChanged);el('reload').addEventListener('click',load);el('retry').addEventListener('click',load);
  form.addEventListener('submit',async event=>{event.preventDefault();if(busy||!state)return;showError('');status.textContent='';const data={uid:state.uid,revision:state.revision,mode:mode.value,rules:[]};if(data.mode==='custom')for(const section of rules.children){if(!section.querySelector('[type=checkbox]').checked)continue;const route=section.querySelector('[data-key=channel]').value;if(!route){showError('请为已启用的支付方式指定通道');return;}const [channel,sub]=route.split(':');data.rules.push({type_id:Number(section.dataset.type),channel_id:Number(channel),subchannel_id:Number(sub),min:section.querySelector('[data-key=min]').value||'0',max:section.querySelector('[data-key=max]').value||'0'});}lock(true);try{const result=await request(data);state.revision=result.revision;status.textContent=result.message;}catch(e){showError(e.message);}finally{lock(false);}});
  load();
})();
