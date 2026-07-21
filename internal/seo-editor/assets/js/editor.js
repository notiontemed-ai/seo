/* ===== структуры статей: загружаются через защищённый proxy.php ===== */
let STRUCTURES = [];
let STRUCTURES_SOURCE = {
  shared_ymyl_rules: {},
  configs: []
};

const APP_DATA = {
  dictionaries: {},
  doctors: [],
  articles: [],
  clinics: [],
  services: []
};

const RELATED_ARTICLES = new Map();


const INTENT_RU={informational:"Информационный",commercial_informational:"Коммерческо-информационный",comparative:"Сравнительный"};

function normalizeStructureConfig(config){
  const blocks = Array.isArray(config.structure)
    ? config.structure.map(item => {
        let code = item.block || '';
        if(item.repeat) code += '×' + String(item.repeat).replace('-', '–');
        return [code, item.required !== false];
      })
    : [];

  return {
    id: config.id || '',
    intent: config.intent || '',
    v: config.version || '',
    name: config.name || config.id || '',
    when: config.when_to_use || '',
    metric: config.primary_metric || '',
    blocks,
    forbidden: Array.isArray(config.forbidden)
      ? config.forbidden.join('; ')
      : (config.forbidden || ''),
    raw: config
  };
}

async function loadArticleStructures(){
  structCards.innerHTML = '<div class="struct-empty">Загружаем структуры из TEMED SEO API…</div>';

  intentCards.querySelectorAll('.intent-card').forEach(card => {
    card.disabled = true;
  });

  try{
    const response = await fetch('proxy.php?action=article_structures', {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json'
      },
      cache: 'no-store'
    });

    const result = await response.json();

    if(response.status === 401){
      window.location.reload();
      return;
    }

    if(!response.ok || !result.success){
      throw new Error(result.error || ('HTTP ' + response.status));
    }

    if(!result.data || !Array.isArray(result.data.configs)){
      throw new Error('В ответе API отсутствует массив data.configs');
    }

    STRUCTURES_SOURCE = result.data;
    STRUCTURES = result.data.configs.map(normalizeStructureConfig);

    const currentIntent = document.getElementById('search_intent').value;

    if(currentIntent){
      renderStructs(currentIntent);
    }else{
      structCards.innerHTML =
        '<div class="struct-empty">Выберите интент, чтобы увидеть доступные структуры</div>';
    }

    document.getElementById('side_status').textContent = 'справочники загружены';

    logAction('Структуры загружены из TEMED SEO API', {
      count: STRUCTURES.length,
      api_version: result.api_version || '',
      versions: [...new Set(STRUCTURES.map(item => item.v))]
    });
    const counts=document.getElementById('apiCounts');
    if(counts&&APP_DATA.doctors.length){
      counts.innerHTML=[
        ['Врачи',APP_DATA.doctors.length],
        ['Статьи',APP_DATA.articles.length],
        ['Разделы',((APP_DATA.dictionaries.article_sections||{}).new||[]).length],
        ['Клиники',APP_DATA.clinics.length],
        ['Услуги',APP_DATA.services.length],
        ['Структуры',STRUCTURES.length]
      ].map(([name,count])=>'<span>'+escapeHtml(name)+': '+count+'</span>').join('');
    }
  }catch(error){
    structCards.innerHTML =
      '<div class="struct-empty">Не удалось загрузить структуры: '
      + String(error.message || error)
      + '</div>';

    document.getElementById('side_status').textContent = 'ошибка загрузки структур';

    logAction('Ошибка загрузки структур', {
      error: String(error.message || error)
    });
  }finally{
    intentCards.querySelectorAll('.intent-card').forEach(card => {
      card.disabled = false;
    });
  }
}



/* ===== справочники TEMED SEO API ===== */
function escapeHtml(value){
  return String(value ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'",'&#039;');
}

function propertyValues(item, code){
  const prop=item&&item.properties?item.properties[code]:null;
  if(!prop||!Array.isArray(prop.values)) return [];
  return prop.values.map(entry=>{
    const value=entry&&Object.prototype.hasOwnProperty.call(entry,'value')?entry.value:entry;
    if(value===null||value===undefined) return '';
    if(typeof value==='string'||typeof value==='number') return String(value);
    if(typeof value==='object'){
      if(value.name) return String(value.name);
      if(value.value) return String(value.value);
      if(value.text) return String(value.text);
      if(value.absolute_url) return String(value.absolute_url);
      if(value.url) return String(value.url);
    }
    return '';
  }).filter(Boolean);
}

function firstProperty(item, codes){
  for(const code of codes){
    const values=propertyValues(item,code);
    if(values.length) return values[0];
  }
  return '';
}

function doctorContext(id){
  const doctor=APP_DATA.doctors.find(item=>String(item.id)===String(id));
  if(!doctor) return null;

  const specialties=[
    ...propertyValues(doctor,'SPECIAL1_LP'),
    ...propertyValues(doctor,'SPECIAL2_LP'),
    ...propertyValues(doctor,'SPECIAL3_LP'),
    ...propertyValues(doctor,'POSITION')
  ].filter((value,index,array)=>array.indexOf(value)===index);

  return {
    id:doctor.id,
    name:doctor.name,
    code:doctor.code||'',
    url:doctor.absolute_url||doctor.url||'',
    specialties,
    experience:firstProperty(doctor,['EXPERIENCE_LP','EXPERIENCE']),
    clinics:propertyValues(doctor,'CLINIC'),
    city:firstProperty(doctor,['CITY_LP']),
    summary:doctor.summary||''
  };
}

function genericContext(items,id){
  const item=items.find(entry=>String(entry.id)===String(id));
  if(!item) return null;
  return {
    id:item.id,
    name:item.name,
    code:item.code||'',
    xml_id:item.xml_id||'',
    url:item.absolute_url||item.url||'',
    section:item.section||null,
    summary:item.summary||'',
    properties:item.properties||{}
  };
}

function setOptions(select,items,placeholder,labelBuilder,valueBuilder){
  const current=select.value;
  select.innerHTML='';

  const empty=document.createElement('option');
  empty.value='';
  empty.textContent=placeholder;
  select.appendChild(empty);

  items.forEach(item=>{
    const option=document.createElement('option');
    option.value=valueBuilder?valueBuilder(item):String(item.id??item.value??'');
    option.textContent=labelBuilder?labelBuilder(item):String(item.name??item.value??'');
    if(item.id!==undefined) option.dataset.id=String(item.id);
    if(item.xml_id!==undefined) option.dataset.xmlId=String(item.xml_id||'');
    select.appendChild(option);
  });

  if([...select.options].some(option=>option.value===current)){
    select.value=current;
  }
}

function enumFallback(items,fallbackValues){
  if(Array.isArray(items)&&items.length) return items;
  return fallbackValues.map((value,index)=>({
    id:'fallback_'+index,
    value,
    xml_id:value.toLowerCase().replace(/[^a-zа-я0-9]+/gi,'_')
  }));
}

function syncEnumSelect(select,idField,xmlField){
  const option=select.selectedOptions[0];
  document.getElementById(idField).value=option&&option.dataset.id?option.dataset.id:'';
  document.getElementById(xmlField).value=option&&option.dataset.xmlId?option.dataset.xmlId:'';
}

function syncSection(){
  const select=document.getElementById('article_section_id');
  const option=select.selectedOptions[0];
  const isNew=select.value==='__new__';

  document.getElementById('newSectionField').classList.toggle('is-hidden', !isNew);
  document.getElementById('article_section').value=
    isNew
      ? document.getElementById('new_article_section').value
      : (option&&option.dataset.name?option.dataset.name:'');
  document.getElementById('article_section_code').value=
    isNew?'':(option&&option.dataset.code?option.dataset.code:'');
}

function doctorDisplayName(doctor){
  const specialty=firstProperty(doctor,['SPECIAL1_LP','POSITION']);
  return doctor.name+(specialty?' — '+specialty:'');
}

function fillDoctorDatalist(listId){
  const list=document.getElementById(listId);
  list.innerHTML='';

  APP_DATA.doctors.forEach(doctor=>{
    const option=document.createElement('option');
    option.value=doctorDisplayName(doctor);
    option.dataset.id=String(doctor.id);
    list.appendChild(option);
  });
}

function resolveDoctorBySearch(searchValue){
  const value=String(searchValue||'').trim().toLowerCase();

  if(!value) return null;

  return APP_DATA.doctors.find(doctor=>
    doctorDisplayName(doctor).toLowerCase()===value
    || String(doctor.name||'').toLowerCase()===value
  ) || null;
}

function syncDoctorSearch(searchId,hiddenId,hiddenNameId,infoId){
  const search=document.getElementById(searchId);
  const doctor=resolveDoctorBySearch(search.value);

  document.getElementById(hiddenId).value=doctor?String(doctor.id):'';
  document.getElementById(hiddenNameId).value=doctor?doctor.name:'';

  const info=document.getElementById(infoId);

  if(!doctor){
    info.textContent=search.value.trim()
      ? 'Выберите врача из выпадающего списка.'
      : '';
    return;
  }

  const context=doctorContext(doctor.id);
  const parts=[];

  if(context.specialties.length) parts.push(context.specialties.join(', '));
  if(context.experience) parts.push('Стаж: '+context.experience);
  if(context.city) parts.push(context.city);
  if(context.clinics.length) parts.push('Клиники: '+context.clinics.join(', '));

  info.textContent=parts.join(' · ')||'Карточка врача выбрана';
}

function renderDoctorInfo(selectId,hiddenNameId,infoId){
  const select=document.getElementById(selectId);
  const context=doctorContext(select.value);
  document.getElementById(hiddenNameId).value=context?context.name:'';

  const info=document.getElementById(infoId);
  if(!context){
    info.textContent='';
    return;
  }

  const parts=[];
  if(context.specialties.length) parts.push(context.specialties.join(', '));
  if(context.experience) parts.push('Стаж: '+context.experience);
  if(context.city) parts.push(context.city);
  if(context.clinics.length) parts.push('Клиники: '+context.clinics.join(', '));

  info.textContent=parts.join(' · ')||'Карточка врача выбрана';
}

function renderGenericInfo(selectId,hiddenNameId,infoId,items){
  const select=document.getElementById(selectId);
  const context=genericContext(items,select.value);
  document.getElementById(hiddenNameId).value=context?context.name:'';

  const info=document.getElementById(infoId);
  if(!context){
    info.textContent='';
    return;
  }

  const parts=[];
  if(context.section&&context.section.name) parts.push(context.section.name);
  if(context.summary) parts.push(context.summary.slice(0,220));
  info.textContent=parts.join(' · ')||'Карточка выбрана';
}

function syncExistingArticle(){
  const selector=document.getElementById('existing_article_selector');
  const key=selector.value;
  const [source,id]=key.includes(':')?key.split(':',2):['',key];
  document.getElementById('existing_article_id').value=id||'';
  document.getElementById('existing_article_source').value=source||'';
}

function articleKey(article){
  return String(article.source||'new')+':'+String(article.id);
}

function renderRelatedResults(items){
  const container=document.getElementById('relatedSearchResults');
  container.innerHTML='';

  if(!items.length){
    container.innerHTML='<div class="struct-empty">Ничего не найдено в загруженном списке статей.</div>';
    return;
  }

  items.forEach(article=>{
    const row=document.createElement('div');
    row.className='result-item';

    const main=document.createElement('div');
    main.className='ri-main';
    main.innerHTML=
      '<div class="ri-title">'+escapeHtml(article.name)+'</div>'+
      '<div class="ri-meta">'+escapeHtml((article.source||'new')+' · ID '+article.id+(article.section&&article.section.name?' · '+article.section.name:''))+'</div>'+
      '<div class="ri-summary">'+escapeHtml((article.summary||article.preview_text||'').slice(0,260))+'</div>';

    const button=document.createElement('button');
    button.type='button';
    button.className='btn';
    button.textContent=RELATED_ARTICLES.has(articleKey(article))?'Добавлено':'Добавить';
    button.disabled=RELATED_ARTICLES.has(articleKey(article));
    button.addEventListener('click',()=>{
      RELATED_ARTICLES.set(articleKey(article),article);
      renderSelectedRelated();
      button.textContent='Добавлено';
      button.disabled=true;
    });

    row.append(main,button);
    container.appendChild(row);
  });
}

function renderSelectedRelated(){
  const textarea=document.getElementById('selected_related_articles');
  const list=document.getElementById('selectedRelatedList');
  const items=[...RELATED_ARTICLES.values()];

  textarea.value=items.map(item=>{
    const url=item.absolute_url||item.url||'';
    return (item.source||'new')+':'+item.id+' | '+item.name+(url?' | '+url:'');
  }).join('\n');

  list.innerHTML='';

  items.forEach(item=>{
    const chip=document.createElement('span');
    chip.className='selected-chip';

    const title=document.createElement('span');
    title.textContent=item.name;

    const remove=document.createElement('button');
    remove.type='button';
    remove.title='Убрать';
    remove.textContent='×';
    remove.addEventListener('click',()=>{
      RELATED_ARTICLES.delete(articleKey(item));
      renderSelectedRelated();
      searchRelatedArticles();
    });

    chip.append(title,remove);
    list.appendChild(chip);
  });
}

function searchRelatedArticles(){
  const query=document.getElementById('context_search_query').value.trim().toLowerCase();
  const terms=query.split(/\s+/).filter(Boolean);

  let items=APP_DATA.articles.filter(article=>{
    if(!terms.length) return true;
    const haystack=[
      article.name,
      article.code,
      article.summary,
      article.preview_text,
      article.section&&article.section.name
    ].filter(Boolean).join(' ').toLowerCase();
    return terms.every(term=>haystack.includes(term));
  });

  renderRelatedResults(items.slice(0,20));
  logAction('Поиск связанных статей',{query,found:items.length,shown:Math.min(items.length,20)});
}

function populateBootstrap(data){
  APP_DATA.dictionaries=data.dictionaries||{};
  APP_DATA.doctors=Array.isArray(data.doctors)?data.doctors:[];
  APP_DATA.articles=data.articles&&Array.isArray(data.articles.items)?data.articles.items:[];
  APP_DATA.clinics=data.clinics&&Array.isArray(data.clinics.items)?data.clinics.items:[];
  APP_DATA.services=data.prices&&Array.isArray(data.prices.items)?data.prices.items:[];

  const dictionaries=APP_DATA.dictionaries;

  const articleTypes=enumFallback(
    dictionaries.article_types,
    ['Обзор','Диагноз','Метод','Вопрос','Операция','Региональная','Сравнение']
  );
  setOptions(
    document.getElementById('article_type'),
    articleTypes,
    '— выбрать —',
    item=>item.value,
    item=>item.value
  );
  [...document.getElementById('article_type').options].forEach(option=>{
    const item=articleTypes.find(entry=>entry.value===option.value);
    if(item){
      option.dataset.id=String(item.id||'');
      option.dataset.xmlId=String(item.xml_id||'');
    }
  });

  const regions=Array.isArray(dictionaries.regions)?dictionaries.regions:[];
  setOptions(
    document.getElementById('region'),
    regions,
    '— не выбран —',
    item=>item.value,
    item=>item.value
  );
  [...document.getElementById('region').options].forEach(option=>{
    const item=regions.find(entry=>entry.value===option.value);
    if(item){
      option.dataset.id=String(item.id||'');
      option.dataset.xmlId=String(item.xml_id||'');
    }
  });

  const templates=enumFallback(dictionaries.article_templates,['default']);
  setOptions(
    document.getElementById('article_template'),
    templates,
    '— выбрать —',
    item=>item.value,
    item=>item.value
  );
  [...document.getElementById('article_template').options].forEach(option=>{
    const item=templates.find(entry=>entry.value===option.value);
    if(item){
      option.dataset.id=String(item.id||'');
      option.dataset.xmlId=String(item.xml_id||'');
    }
  });
  if([...document.getElementById('article_template').options].some(option=>option.value==='default')){
    document.getElementById('article_template').value='default';
  }

  const sections=dictionaries.article_sections&&Array.isArray(dictionaries.article_sections.new)
    ? dictionaries.article_sections.new
    : [];
  const sectionSelect=document.getElementById('article_section_id');
  sectionSelect.innerHTML='<option value="">— без раздела —</option>';
  sections.forEach(section=>{
    const option=document.createElement('option');
    option.value=String(section.id);
    option.dataset.name=section.name||'';
    option.dataset.code=section.code||'';
    option.textContent='— '.repeat(Math.max(0,(section.depth_level||1)-1))+section.name;
    sectionSelect.appendChild(option);
  });
  const newSection=document.createElement('option');
  newSection.value='__new__';
  newSection.textContent='＋ Создать новый раздел';
  sectionSelect.appendChild(newSection);

  fillDoctorDatalist('authorDoctorList');
  fillDoctorDatalist('reviewerDoctorList');

  setOptions(
    document.getElementById('clinic_id'),
    APP_DATA.clinics,
    '— не выбрана —',
    item=>item.name,
    item=>String(item.id)
  );

  setOptions(
    document.getElementById('service_id'),
    APP_DATA.services,
    '— не выбрана —',
    item=>item.name,
    item=>String(item.id)
  );

  const existing=document.getElementById('existing_article_selector');
  existing.innerHTML='<option value="">— выбрать статью —</option>';
  APP_DATA.articles.forEach(article=>{
    const option=document.createElement('option');
    option.value=articleKey(article);
    option.textContent=(article.source==='legacy'?'Старая':'Новая')+' · '+article.name+' · ID '+article.id;
    existing.appendChild(option);
  });

  const counts=document.getElementById('apiCounts');
  counts.innerHTML=[
    ['Врачи',APP_DATA.doctors.length],
    ['Статьи',APP_DATA.articles.length],
    ['Разделы',sections.length],
    ['Клиники',APP_DATA.clinics.length],
    ['Услуги',APP_DATA.services.length],
    ['Структуры',STRUCTURES.length]
  ].map(([name,count])=>'<span>'+escapeHtml(name)+': '+count+'</span>').join('');

  document.getElementById('side_status').textContent='справочники загружены';

  syncEnumSelect(document.getElementById('article_type'),'article_type_id','article_type_xml_id');
  syncEnumSelect(document.getElementById('region'),'region_id','region_xml_id');
  syncEnumSelect(document.getElementById('article_template'),'article_template_id','article_template_xml_id');
  syncSection();
  renderTemplatesFromApi(templates);

  logAction('Загружены справочники TEMED SEO API',{
    doctors:APP_DATA.doctors.length,
    articles:APP_DATA.articles.length,
    sections:sections.length,
    clinics:APP_DATA.clinics.length,
    services:APP_DATA.services.length,
    article_types:articleTypes.length,
    regions:regions.length,
    templates:templates.length
  });
}

async function loadBootstrapData(){
  try{
    const response=await fetch('proxy.php?action=bootstrap',{
      method:'GET',
      credentials:'same-origin',
      headers:{'Accept':'application/json'},
      cache:'no-store'
    });
    const result=await response.json();

    if(response.status===401){
      window.location.reload();
      return;
    }
    if(!response.ok||!result.success){
      throw new Error(result.error||('HTTP '+response.status));
    }

    populateBootstrap(result.data||{});
  }catch(error){
    document.getElementById('apiCounts').innerHTML=
      '<span>Ошибка загрузки справочников: '+escapeHtml(error.message||error)+'</span>';
    document.getElementById('side_status').textContent='ошибка справочников';
    logAction('Ошибка загрузки bootstrap',{error:String(error.message||error)});
  }
}

document.getElementById('existing_article_selector').addEventListener('change',syncExistingArticle);
document.getElementById('article_type').addEventListener('change',()=>{
  syncEnumSelect(document.getElementById('article_type'),'article_type_id','article_type_xml_id');
});
document.getElementById('region').addEventListener('change',()=>{
  syncEnumSelect(document.getElementById('region'),'region_id','region_xml_id');
});
document.getElementById('article_template').addEventListener('change',()=>{
  syncEnumSelect(document.getElementById('article_template'),'article_template_id','article_template_xml_id');
  const value=document.getElementById('article_template').value;
  if(value) selectTemplate(value);
});
document.getElementById('article_section_id').addEventListener('change',syncSection);
document.getElementById('new_article_section').addEventListener('input',syncSection);
['input','change','blur'].forEach(eventName=>{
  document.getElementById('author_search').addEventListener(eventName,()=>{
    syncDoctorSearch('author_search','author_id','author','authorInfo');
  });

  document.getElementById('medical_reviewer_search').addEventListener(eventName,()=>{
    syncDoctorSearch(
      'medical_reviewer_search',
      'medical_reviewer_id',
      'medical_reviewer',
      'reviewerInfo'
    );
  });
});
document.getElementById('clinic_id').addEventListener('change',()=>{
  renderGenericInfo('clinic_id','clinic','clinicInfo',APP_DATA.clinics);
});
document.getElementById('service_id').addEventListener('change',()=>{
  renderGenericInfo('service_id','service','serviceInfo',APP_DATA.services);
});
document.getElementById('context_search_query').addEventListener('keydown',event=>{
  if(event.key==='Enter'){
    event.preventDefault();
    searchRelatedArticles();
  }
});


/* ===== state / dom ===== */
const form=document.getElementById('articleForm');
const briefPreview=document.getElementById('briefPreview');
const actionLog=document.getElementById('action_log');
const steps=[...document.querySelectorAll('.step')];
const navs=[...document.querySelectorAll('.navstep')];
let cur=0;
const visited=new Set([0]);

/* ===== navigation ===== */
function goto(n){
  cur=n;visited.add(n);
  steps.forEach(s=>s.classList.toggle('on',+s.dataset.step===n));
  navs.forEach(b=>{
    const i=+b.dataset.step;
    b.classList.toggle('current',i===n);
    b.classList.toggle('done',visited.has(i)&&i!==n);
  });
  document.querySelector('.main').scrollIntoView({behavior:'instant',block:'start'});
  window.scrollTo({top:0});
}
navs.forEach(b=>b.addEventListener('click',()=>goto(+b.dataset.step)));
document.querySelectorAll('[data-nav]').forEach(b=>{
  b.addEventListener('click',()=>goto(b.dataset.nav==='next'?Math.min(cur+1,steps.length-1):Math.max(cur-1,0)));
});

/* ===== mode segment ===== */
const modeSeg=document.getElementById('modeSeg');
modeSeg.querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>{
  modeSeg.querySelectorAll('button').forEach(x=>x.classList.remove('on'));
  b.classList.add('on');
  const mode=b.dataset.mode;
  document.getElementById('workflow_mode').value=mode;
  document.getElementById('existingArticleField').classList.toggle('is-hidden', mode!=='rework');
  document.getElementById('topMode').textContent=mode==='rework'?'Переработка':'Новая статья';
  logAction('Выбран режим: '+mode);
}));

/* ===== task name -> topbar ===== */
document.getElementById('task_name').addEventListener('input',e=>{
  document.getElementById('topTaskName').textContent=e.target.value||'Новая задача';
});

/* ===== intent -> structures ===== */
const intentCards=document.getElementById('intentCards');
const structCards=document.getElementById('structCards');
const structDetail=document.getElementById('structDetail');

intentCards.querySelectorAll('.intent-card').forEach(c=>c.addEventListener('click',()=>{
  intentCards.querySelectorAll('.intent-card').forEach(x=>x.classList.remove('on'));
  c.classList.add('on');
  const intent=c.dataset.intent;
  document.getElementById('search_intent').value=intent;
  renderStructs(intent);
  selectStruct(null);
  logAction('Выбран интент: '+intent);
}));

function renderStructs(intent){
  const list=STRUCTURES.filter(s=>s.intent===intent);
  structCards.innerHTML='';
  list.forEach(s=>{
    const b=document.createElement('button');
    b.type='button';b.className='struct-card';b.dataset.sid=s.id;
    b.innerHTML=`<div class="sn">${s.name}</div><div class="sid">${s.id} · v${s.v}</div><div class="sw">${s.when}</div><div class="sm">Метрика теста: ${s.metric}</div>`;
    b.addEventListener('click',()=>selectStruct(s.id));
    structCards.appendChild(b);
  });
}

function selectStruct(sid){
  structCards.querySelectorAll('.struct-card').forEach(x=>x.classList.toggle('on',x.dataset.sid===sid));
  const s=STRUCTURES.find(x=>x.id===sid);
  document.getElementById('article_structure').value=s?s.id:'';
  document.getElementById('article_structure_name').value=s?s.name:'';
  document.getElementById('article_structure_version').value=s?s.v:'';
  document.getElementById('topStructBadge').textContent=s?s.id+' · v'+s.v:'структура не выбрана';
  if(s){
    structDetail.classList.add('on');
    structDetail.innerHTML=
      `<b class="struct-detail-title">${s.name}</b> <span class="mono struct-detail-meta">· ${s.id} · v${s.v} · ${INTENT_RU[s.intent]}</span>
       <div class="blocks">${s.blocks.map(([b,req])=>`<span class="blk${req?' req':''}">${b}${req?'':' (опц.)'}</span>`).join('')}</div>
       <div class="fb">Запрещено: ${s.forbidden}</div>`;
    logAction('Выбрана структура',{id:s.id,version:s.v});
  }else{
    structDetail.classList.remove('on');structDetail.innerHTML='';
  }
}

/* ===== log / brief (совместимо с прототипом) ===== */
function formToObject(){
  const data=new FormData(form);const result={};
  for(const [key,value] of data.entries()) result[key]=value;
  return result;
}
function logAction(action,details){
  const timestamp=new Date().toISOString();
  actionLog.value+='['+timestamp+'] '+action;
  if(details) actionLog.value+='\n'+JSON.stringify(details,null,2);
  actionLog.value+='\n\n';
  actionLog.scrollTop=actionLog.scrollHeight;
}
function buildBrief(){
  const data=formToObject();

  const articleTypeOption=document.getElementById('article_type').selectedOptions[0];
  const regionOption=document.getElementById('region').selectedOptions[0];
  const templateOption=document.getElementById('article_template').selectedOptions[0];

  const relatedArticles=[...RELATED_ARTICLES.values()].map(article=>({
    id:article.id,
    source:article.source||'new',
    name:article.name,
    code:article.code||'',
    url:article.absolute_url||article.url||'',
    section:article.section||null,
    summary:article.summary||''
  }));

  const brief={
    workflow:{
      mode:data.workflow_mode,
      existing_article_id:data.existing_article_id||'',
      existing_article_source:data.existing_article_source||'',
      task_name:data.task_name
    },
    search_task:{
      topic:data.topic,
      primary_query:data.primary_query,
      secondary_queries:data.secondary_queries
        ? data.secondary_queries.split('\n').map(value=>value.trim()).filter(Boolean)
        : [],
      search_intent:data.search_intent,
      article_structure:data.article_structure,
      article_structure_name:data.article_structure_name,
      article_structure_version:data.article_structure_version,
      article_type:{
        id:data.article_type_id||'',
        value:data.article_type||'',
        xml_id:data.article_type_xml_id||''
      },
      region:{
        id:data.region_id||'',
        value:data.region||'',
        xml_id:data.region_xml_id||''
      },
      reader_goal:data.reader_goal
    },
    placement:{
      section:{
        id:data.article_section_id&&data.article_section_id!=='__new__'
          ? data.article_section_id
          : '',
        name:data.article_section||'',
        code:data.article_section_code||'',
        create_new:data.article_section_id==='__new__',
        new_name:data.new_article_section||''
      },
      template:{
        id:data.article_template_id||'',
        value:data.article_template||'',
        xml_id:data.article_template_xml_id||''
      }
    },
    medical_roles:{
      author:doctorContext(data.author_id),
      medical_reviewer:doctorContext(data.medical_reviewer_id),
      expert_material:data.expert_material
    },
    internal_context:{
      clinic:genericContext(APP_DATA.clinics,data.clinic_id),
      service:genericContext(APP_DATA.services,data.service_id),
      context_search_query:data.context_search_query,
      related_articles:relatedArticles,
      internal_facts:data.internal_facts
    },
    sources:{
      source_requirements:data.source_requirements,
      provided_sources:data.provided_sources,
      approved_sources:data.approved_sources
    },
    instructions:{
      required_content:data.required_content,
      forbidden_content:data.forbidden_content,
      tone_notes:data.tone_notes,
      length_target:data.length_target
    },
    generation:{
      mode:data.generation_mode||'builtin',
      external_text_loaded:!!(data.external_generated_text&&data.external_generated_text.trim())
    },
    med_review:{
      questions:data.med_questions||'',
      answers:data.med_answers||''
    },
    layout:{
      template:data.layout_template||data.article_template||'',
      available_html_blocks:Array.isArray(APP_DATA.dictionaries.html_blocks)
        ? APP_DATA.dictionaries.html_blocks
        : [],
      available_forms:Array.isArray(APP_DATA.dictionaries.forms)
        ? APP_DATA.dictionaries.forms
        : [],
      notes:data.layout_notes||'',
      task_ready:!!(data.layout_task&&data.layout_task.trim())
    }
  };

  briefPreview.textContent=JSON.stringify(brief,null,2);
  logAction('Сформирован предварительный JSON задания',brief);
  return brief;
}

/* ===== защищённая связь с n8n через proxy.php ===== */
function currentArticleObject(){
  const parseMaybeJson=value=>{
    const text=String(value||'').trim();
    if(!text) return [];
    try{return JSON.parse(text);}catch(_){return text;}
  };

  return {
    name:document.getElementById('result_name').value.trim(),
    code:document.getElementById('result_code').value.trim(),
    seo_title:document.getElementById('result_seo_title').value.trim(),
    meta_description:document.getElementById('result_meta_description').value.trim(),
    preview_text:document.getElementById('result_preview').value.trim(),
    short_answer:document.getElementById('result_short_answer').value.trim(),
    detail_html:document.getElementById('result_detail_html').value.trim(),
    sources:parseMaybeJson(document.getElementById('result_sources').value),
    related_articles:parseMaybeJson(document.getElementById('result_related_articles').value)
  };
}

function selectedStructureConfig(){
  const id=document.getElementById('article_structure').value;
  const item=STRUCTURES.find(entry=>entry.id===id);
  return item&&item.raw?item.raw:null;
}

function actionPayload(){
  const brief=buildBrief();

  return {
    ...brief,
    brief,
    structure_config:selectedStructureConfig(),
    shared_ymyl_rules:STRUCTURES_SOURCE.shared_ymyl_rules||{},
    generated_outline:document.getElementById('generated_outline').value.trim(),
    article:currentArticleObject(),
    med_questions:document.getElementById('med_questions').value.trim(),
    med_answers:document.getElementById('med_answers').value.trim(),
    validation_report:document.getElementById('validation_report').value.trim(),
    revision_request:document.getElementById('revision_request').value.trim()
  };
}

function setButtonBusy(button,busy){
  if(!button)return;

  if(busy){
    button.dataset.originalText=button.textContent;
    button.textContent='Выполняется…';
    button.disabled=true;
    button.setAttribute('aria-busy','true');
  }else{
    button.textContent=button.dataset.originalText||button.textContent;
    button.disabled=false;
    button.removeAttribute('aria-busy');
  }
}

function readableError(payload,response){
  if(payload&&payload.message)return payload.message;
  if(payload&&payload.error)return payload.error;
  return 'Ошибка HTTP '+response.status;
}

async function callN8n(action,data,button){
  setButtonBusy(button,true);
  document.getElementById('side_status').textContent='n8n: '+action+'…';

  try{
    const response=await fetch('proxy.php',{
      method:'POST',
      credentials:'same-origin',
      cache:'no-store',
      headers:{
        'Content-Type':'application/json',
        'Accept':'application/json'
      },
      body:JSON.stringify({
        action,
        data
      })
    });

    const text=await response.text();
    let payload;

    try{
      payload=JSON.parse(text);
    }catch(_){
      throw new Error('Сервер вернул не JSON: '+text.slice(0,1000));
    }

    if(response.status===401){
      window.location.reload();
      throw new Error('Сессия редактора завершена.');
    }

    if(!response.ok||payload.success!==true){
      throw new Error(readableError(payload,response));
    }

    document.getElementById('side_status').textContent='n8n: выполнено';
    logAction('n8n: '+action,payload);
    return payload.data||{};
  }catch(error){
    document.getElementById('side_status').textContent='n8n: ошибка';
    logAction('Ошибка n8n: '+action,{
      error:String(error.message||error)
    });
    alert('Не удалось выполнить действие:\n\n'+String(error.message||error));
    throw error;
  }finally{
    setButtonBusy(button,false);
  }
}

function valueToText(value){
  if(value===null||value===undefined)return '';
  if(typeof value==='string')return value;
  return JSON.stringify(value,null,2);
}

function setArticleResult(article){
  if(!article||typeof article!=='object')return;

  document.getElementById('result_name').value=article.name||'';
  document.getElementById('result_code').value=article.code||'';
  document.getElementById('result_seo_title').value=article.seo_title||'';
  document.getElementById('result_meta_description').value=article.meta_description||'';
  document.getElementById('result_preview').value=article.preview_text||article.preview||'';
  document.getElementById('result_short_answer').value=article.short_answer||'';
  document.getElementById('result_detail_html').value=article.detail_html||article.html||'';
  document.getElementById('result_sources').value=valueToText(article.sources||[]);
  document.getElementById('result_related_articles').value=valueToText(article.related_articles||[]);
}

function questionsToText(data){
  if(data.questions_text)return data.questions_text;
  if(!Array.isArray(data.questions))return '';

  return data.questions.map((item,index)=>{
    const id=item.id||('M'+(index+1));
    const lines=[
      id+'. '+(item.question||''),
      item.fragment?'Фрагмент: '+item.fragment:'',
      item.reason?'Причина: '+item.reason:'',
      item.suggested_safe_wording?'Безопасная формулировка: '+item.suggested_safe_wording:''
    ].filter(Boolean);
    return lines.join('\n');
  }).join('\n\n');
}

async function runResearchSources(button){
  const data=await callN8n('research_sources',actionPayload(),button);

  document.getElementById('approved_sources').value=
    data.approved_sources_text
    || valueToText(data.candidates||[]);

  logAction('Источники подготовлены',{
    candidates:Array.isArray(data.candidates)?data.candidates.length:0,
    search_queries:Array.isArray(data.search_queries)?data.search_queries.length:0
  });
}

async function runGenerateOutline(button){
  const brief=buildBrief();

  if(!brief.search_task.topic||!brief.search_task.primary_query){
    alert('Сначала заполните тему и основной поисковый запрос.');
    return;
  }

  if(!brief.search_task.search_intent||!brief.search_task.article_structure){
    alert('Сначала выберите интент и структуру статьи.');
    return;
  }

  const data=await callN8n('generate_outline',actionPayload(),button);

  document.getElementById('generated_outline').value=
    data.outline_text
    || valueToText(data.outline||data);

  document.getElementById('generated_outline').dataset.approved='N';
  document.getElementById('side_status').textContent='план сформирован';
}

async function runApproveOutline(button){
  const outline=document.getElementById('generated_outline').value.trim();

  if(!outline){
    alert('Сначала сформируйте план статьи.');
    return;
  }

  await callN8n('approve_outline',actionPayload(),button);
  document.getElementById('generated_outline').dataset.approved='Y';
  document.getElementById('side_status').textContent='план утверждён';
  alert('План утверждён.');
}

async function runGenerateArticle(button){
  const outline=document.getElementById('generated_outline').value.trim();

  if(!outline){
    alert('Сначала сформируйте и проверьте план статьи.');
    return;
  }

  if(document.getElementById('generated_outline').dataset.approved!=='Y'){
    const proceed=confirm('План ещё не отмечен как утверждённый. Всё равно запустить генерацию?');
    if(!proceed)return;
  }

  const data=await callN8n('generate_article',actionPayload(),button);
  setArticleResult(data);

  if(Array.isArray(data.medical_review_questions)){
    document.getElementById('med_questions').value=questionsToText({
      questions:data.medical_review_questions
    });
  }

  document.getElementById('side_status').textContent='статья сгенерирована';
  goto(9);
}

async function runExtractMedQuestions(button){
  const article=currentArticleObject();

  if(!article.detail_html){
    alert('Сначала сформируйте или загрузите текст статьи.');
    return;
  }

  const data=await callN8n('extract_med_questions',actionPayload(),button);
  document.getElementById('med_questions').value=questionsToText(data);
  document.getElementById('side_status').textContent='вопросы подготовлены';
}

async function runApplyMedAnswers(button){
  const answers=document.getElementById('med_answers').value.trim();

  if(!answers){
    alert('Сначала вставьте ответы медицинского редактора.');
    return;
  }

  const data=await callN8n('apply_med_answers',actionPayload(),button);
  setArticleResult(data.article||data);
  document.getElementById('side_status').textContent='ответы врача применены';
  goto(9);
}

async function runValidateArticle(button){
  const article=currentArticleObject();

  if(!article.detail_html){
    alert('Текст статьи пока пуст.');
    return;
  }

  const data=await callN8n('validate_article',actionPayload(),button);
  document.getElementById('validation_report').value=
    data.report_text
      ? data.report_text+'\n\n'+JSON.stringify(data,null,2)
      : JSON.stringify(data,null,2);

  document.getElementById('side_status').textContent=
    data.passed?'проверка пройдена':'есть замечания';
}

async function runReviseArticle(button){
  const comments=document.getElementById('revision_request').value.trim();

  if(!comments){
    alert('Укажите, что именно нужно переработать.');
    return;
  }

  const data=await callN8n('revise_article',actionPayload(),button);
  setArticleResult(data.article||data);
  document.getElementById('side_status').textContent='правки внесены';
  goto(9);
}

async function refreshDictionaries(button){
  setButtonBusy(button,true);
  try{
    await Promise.all([
      loadArticleStructures(),
      loadBootstrapData()
    ]);
    document.getElementById('side_status').textContent='справочники обновлены';
  }finally{
    setButtonBusy(button,false);
  }
}

function propertyTextFromArticle(item,code){
  const values=propertyValues(item,code);
  if(!values.length)return '';
  return values.length===1?values[0]:values.join('\n');
}

async function loadExistingArticle(button){
  const id=document.getElementById('existing_article_id').value;
  const source=document.getElementById('existing_article_source').value||'new';

  if(!id){
    alert('Выберите статью из списка.');
    return;
  }

  setButtonBusy(button,true);

  try{
    const url='proxy.php?action=article&id='
      +encodeURIComponent(id)
      +'&source='+encodeURIComponent(source);

    const response=await fetch(url,{
      credentials:'same-origin',
      headers:{'Accept':'application/json'},
      cache:'no-store'
    });

    const payload=await response.json();

    if(!response.ok||payload.success!==true){
      throw new Error(readableError(payload,response));
    }

    const item=payload.data&&payload.data.item
      ? payload.data.item
      : payload.data;

    if(!item||typeof item!=='object'){
      throw new Error('API не вернул карточку статьи.');
    }

    document.getElementById('task_name').value='Переработка: '+(item.name||'');
    document.getElementById('result_name').value=item.name||'';
    document.getElementById('result_code').value=item.code||'';
    document.getElementById('result_preview').value=
      item.preview_text||item.summary||propertyTextFromArticle(item,'INFO');
    document.getElementById('result_detail_html').value=
      item.detail_html||item.detail_text||item.html||'';
    document.getElementById('result_seo_title').value=
      item.seo_title||item.meta_title||'';
    document.getElementById('result_meta_description').value=
      item.meta_description||'';
    document.getElementById('result_short_answer').value=
      propertyTextFromArticle(item,'SHORT_ANSWER');
    document.getElementById('result_sources').value=
      propertyTextFromArticle(item,'SOURCES');

    logAction('Загружена существующая статья',{
      id,
      source,
      name:item.name||''
    });

    document.getElementById('side_status').textContent='статья загружена';
    goto(9);
  }catch(error){
    alert('Не удалось загрузить статью:\n\n'+String(error.message||error));
  }finally{
    setButtonBusy(button,false);
  }
}

document.getElementById('generated_outline').addEventListener('input',()=>{
  document.getElementById('generated_outline').dataset.approved='N';
});

document.querySelectorAll('[data-action]').forEach(button=>{
  button.addEventListener('click',async()=>{
    const action=button.dataset.action;

    try{
      if(action==='open_draft_save_modal'){await openDraftSaveModal();return;}
      if(action==='refresh_drafts'){await refreshDrafts();return;}
      if(action==='build_brief'){buildBrief();return;}
      if(action==='search_context'){searchRelatedArticles();return;}
      if(action==='build_external_task'){buildExternalTask();return;}
      if(action==='load_external_text'){loadExternalText();return;}
      if(action==='build_layout_task'){await buildLayoutTask();return;}
      if(action==='validate_layout_result'){validateExternalLayoutResult();return;}
      if(action==='preview_layout_result'){previewExternalLayoutResult(button);return;}
      if(action==='apply_layout_result'){await applyExternalLayoutResult();return;}
      if(action==='clear_layout_result'){clearExternalLayoutResult();return;}
      if(action==='restore_pre_layout_article'){restorePreLayoutArticle();return;}
      if(action==='load_dictionaries'){await refreshDictionaries(button);return;}
      if(action==='load_existing_article'){await loadExistingArticle(button);return;}
      if(action==='research_sources'){await runResearchSources(button);return;}
      if(action==='generate_outline'){await runGenerateOutline(button);return;}
      if(action==='approve_outline'){await runApproveOutline(button);return;}
      if(action==='generate_article'){await runGenerateArticle(button);return;}
      if(action==='extract_med_questions'){await runExtractMedQuestions(button);return;}
      if(action==='apply_med_answers'){await runApplyMedAnswers(button);return;}
      if(action==='validate_article'){await runValidateArticle(button);return;}
      if(action==='revise_article'){await runReviseArticle(button);return;}

      if(action==='download_bitrix_xml'){await downloadBitrixXml(button);return;}
      if(action==='check_internal_uniqueness'){await runInternalUniqueness(button);return;}
      if(action==='start_external_uniqueness'){await startExternalUniqueness(button);return;}
      if(action==='run_all_uniqueness'){await runInternalUniqueness(button);await startExternalUniqueness(button);return;}
      if(action==='create_bitrix_draft'){alert('Создание черновика в Bitrix отключено: будущая write-функция.');return;}

      alert('Для действия пока нет обработчика: '+action);
    }catch(_){
      // Сообщение уже показано в обработчике.
    }
  });
});

logAction('Интерфейс инициализирован');
Promise.all([
  loadArticleStructures(),
  loadBootstrapData()
]).then(()=>{
  const counts=document.getElementById('apiCounts');
  if(counts&&counts.textContent.includes('Загрузка')){
    counts.innerHTML='<span>Справочники загружены</span>';
  }
});

/* ===================== v2: генерация, медредактура, вёрстка, тема ===================== */

/* ---- тема (в памяти; при встраивании в веб-приложение добавить сохранение) ---- */
const themeBtn=document.getElementById('themeBtn');
let theme='light';
try{ if(window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches) theme='dark'; }catch(e){}
function applyTheme(t){
  document.documentElement.setAttribute('data-theme',t);
  themeBtn.textContent=t==='dark'?'☀':'☾';
  themeBtn.title=t==='dark'?'Светлая тема':'Тёмная тема';
}
applyTheme(theme);
themeBtn.addEventListener('click',()=>{theme=theme==='dark'?'light':'dark';applyTheme(theme);logAction('Переключена тема: '+theme);});

/* ---- способ генерации ---- */
const genSeg=document.getElementById('genSeg');
if(genSeg){
  genSeg.querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>{
    genSeg.querySelectorAll('button').forEach(x=>x.classList.remove('on'));
    b.classList.add('on');
    const m=b.dataset.genmode;
    document.getElementById('generation_mode').value=m;
    document.getElementById('panelBuiltin').classList.toggle('on',m==='builtin');
    document.getElementById('panelExternal').classList.toggle('on',m==='external');
    logAction('Способ генерации: '+(m==='builtin'?'встроенный ассистент':'внешний ассистент'));
  }));
}

/* ---- пакет задания для внешнего ассистента ---- */
const YMYL_SHORT=[
 'Статья от имени врача клиники (специализация и стаж указываются).',
 'Обязателен дисклеймер: материал информационный, имеются противопоказания, нужна консультация специалиста.',
 'Цифры и медицинские утверждения — только с источником (гайдлайны, PubMed). Без источника цифру не приводить.',
 'Запрещены заочные диагнозы и назначения читателю.',
 'Запрещены гарантии результата лечения.',
 'Если тема допускает опасные состояния — обязателен блок «когда обращаться к врачу срочно».'
];
function buildExternalTask(){
  const brief=buildBrief();
  const s=STRUCTURES.find(x=>x.id===brief.search_task.article_structure);
  const raw=s&&s.raw?s.raw:null;
  const sharedRules=STRUCTURES_SOURCE.shared_ymyl_rules||{};
  const lines=[];

  lines.push('# ЗАДАНИЕ ДЛЯ ИИ-АССИСТЕНТА · SEO-статья клиники TEMED');
  lines.push('');
  lines.push('## Роль');
  lines.push('Ты — медицинский редактор клиники TEMED. Подготовь статью строго по техническому заданию и выбранной конфигурации.');
  lines.push('');
  lines.push('## Общие медицинские правила');

  const sharedEntries=Object.entries(sharedRules).filter(([key])=>key!=='note');

  if(sharedEntries.length){
    sharedEntries.forEach(([,value])=>lines.push('- '+value));
  }else{
    YMYL_SHORT.forEach(rule=>lines.push('- '+rule));
  }

  lines.push('');

  if(raw){
    lines.push('## Выбранная структура');
    lines.push(JSON.stringify(raw,null,2));
  }else{
    lines.push('## Структура не выбрана');
    lines.push('Вернитесь на шаг «Поисковая задача» и выберите структуру статьи.');
  }

  lines.push('');
  lines.push('## Техническое задание');
  lines.push(JSON.stringify(brief,null,2));
  lines.push('');
  lines.push('## Формат результата');
  lines.push('Верни один JSON-объект со следующими полями:');
  lines.push('- name — название статьи;');
  lines.push('- code — символьный код URL;');
  lines.push('- seo_title — SEO title;');
  lines.push('- meta_description — meta description;');
  lines.push('- preview_text — анонс;');
  lines.push('- short_answer — краткий ответ;');
  lines.push('- detail_html — полный текст статьи в HTML;');
  lines.push('- sources — массив использованных источников;');
  lines.push('- medical_review_questions — массив формулировок и вопросов, требующих подтверждения врача.');
  lines.push('');
  lines.push('Не добавляй пояснений до или после JSON.');

  const pkg=lines.join('\\n');

  document.getElementById('externalTaskPreview').textContent=pkg;

  logAction('Сформирован пакет задания для внешнего ассистента',{
    structure:s?s.id:null,
    structure_version:s?s.v:null,
    length:pkg.length
  });

  return pkg;
}

/* ---- загрузка внешнего текста ---- */
function loadExternalText(){
  const t=document.getElementById('external_generated_text').value.trim();
  if(!t){alert('Поле пустое: вставьте текст, полученный от внешнего ассистента.');return;}
  document.getElementById('result_detail_html').value=t;
  document.getElementById('side_status').textContent='текст загружен (внешний ассистент)';
  logAction('Текст внешнего ассистента загружен в результат',{chars:t.length});
  goto(9);
}

/* ---- шаблоны вёрстки ---- */
const TEMPLATES=[
 {id:'default',name:'Default',desc:'Стандартная статья temed.ru: оглавление, блок автора, контент, FAQ, источники, форма записи',ready:true},
 {id:'longread',name:'Longread',desc:'Расширенный лонгрид с врезками, якорной навигацией и иллюстрациями',ready:false},
 {id:'compare',name:'Compare',desc:'Сравнительный шаблон: сводная таблица и карточки методов',ready:false}
];
const tplCards=document.getElementById('tplCards');
function renderTemplates(){
  if(!tplCards)return;
  tplCards.innerHTML='';
  TEMPLATES.forEach(t=>{
    const b=document.createElement('button');
    b.type='button';
    b.className='struct-card'+(t.ready?'':' tpl-off');
    b.dataset.tid=t.id;
    b.innerHTML=`<div class="sn">${t.name}</div><div class="sid">${t.id}</div><div class="sw">${t.desc}</div><div class="sm">${t.ready?'Доступен':'Нет в API — скоро'}</div>`;
    if(t.ready)b.addEventListener('click',()=>selectTemplate(t.id));
    tplCards.appendChild(b);
  });
}
function renderTemplatesFromApi(items){
  if(!Array.isArray(items)||!items.length) return;

  TEMPLATES.length=0;
  items.forEach(item=>{
    TEMPLATES.push({
      id:item.value||item.xml_id||String(item.id),
      name:item.value||item.xml_id||String(item.id),
      desc:'Шаблон из справочника TEMED SEO API',
      ready:true
    });
  });

  renderTemplates();

  const selected=document.getElementById('article_template').value;
  if(selected&&TEMPLATES.some(item=>item.id===selected)){
    selectTemplate(selected);
  }else if(TEMPLATES[0]){
    selectTemplate(TEMPLATES[0].id);
  }
}

function selectTemplate(tid){
  tplCards.querySelectorAll('.struct-card').forEach(x=>x.classList.toggle('on',x.dataset.tid===tid));
  document.getElementById('layout_template').value=tid;
  const sel=document.getElementById('article_template');
  if(sel && [...sel.options].some(o=>o.value===tid)) sel.value=tid;
  logAction('Выбран шаблон вёрстки: '+tid);
}
renderTemplates();
selectTemplate('default');

/* ---- задание на вёрстку ---- */
async function calculateLayoutSourceHash(article=currentArticleObject(),template=fieldValue('layout_template')){return browserHash(JSON.stringify({name:article.name,preview_text:article.preview_text,short_answer:article.short_answer,detail_html:article.detail_html,sources:article.sources,related_articles:article.related_articles,template}));}
async function buildLayoutTask(){
  const d=formToObject();
  if(!d.layout_template){alert('Выберите шаблон страницы.');return;}
  const article=currentArticleObject();
  const html=article.detail_html||'';
  const taskId='layout-'+new Date().toISOString().replace(/[^0-9]/g,'').slice(0,14)+'-'+Math.random().toString(36).slice(2,8);
  const sourceHash=await calculateLayoutSourceHash(article,d.layout_template);
  const task={
    task_type:'layout',
    schema_version:'1.0',
    task_id:taskId,
    source_hash:sourceHash,
    template:d.layout_template,
    instructions:{
      mode:'layout_only',
      preserve_medical_meaning:true,
      return_json_only:true,
      return_html_fragment_only:true,
      rules:[
        'Это вёрстка, а не повторное написание статьи.',
        'Нельзя менять медицинский смысл.',
        'Нельзя менять показания, противопоказания, риски, прогноз, сроки, цифры, диагнозы, рекомендации и выводы врача.',
        'Нельзя добавлять новые медицинские утверждения.',
        'Допустимо перестраивать HTML, добавлять контейнеры, оглавление и якоря, оформлять таблицы и FAQ, подключать разрешённые блоки и расставлять переданные внутренние ссылки.',
        'Вернуть HTML-фрагмент для DETAIL_TEXT Bitrix без <!doctype>, <html>, <head>, <body>.',
        'Не добавлять <script>, <iframe>, <object>, <embed>, произвольный JavaScript и внешние стили.',
        'Использовать только переданные available_forms и available_html_blocks.',
        'Не добавлять пояснения до или после JSON.'
      ]
    },
    article:{...article,preview_text:article.preview_text,sources:article.sources||[],related_articles:article.related_articles||[]},
    diagnostics:{
      html_chars:html.length,
      h2_count:(html.match(/<h2\b/gi)||[]).length,
      has_tables:/<table\b/i.test(html),
      sources_count:Array.isArray(article.sources)?article.sources.length:String(d.result_sources||'').split('\n').filter(Boolean).length,
      related_articles_count:Array.isArray(article.related_articles)?article.related_articles.length:String(d.result_related_articles||'').split('\n').filter(Boolean).length
    },
    author:doctorContext(d.author_id),
    medical_reviewer:doctorContext(d.medical_reviewer_id),
    section:{id:d.article_section_id||'',name:d.article_section||'',code:d.article_section_code||''},
    clinic:genericContext(APP_DATA.clinics,d.clinic_id),
    service:genericContext(APP_DATA.services,d.service_id),
    available_html_blocks:Array.isArray(APP_DATA.dictionaries.html_blocks)?APP_DATA.dictionaries.html_blocks:[],
    available_forms:Array.isArray(APP_DATA.dictionaries.forms)?APP_DATA.dictionaries.forms:[],
    notes:d.layout_notes||'',
    checklist:['Проверить, что медицинский смысл исходной статьи сохранён.','Оглавление по h2/h3.','FAQ с разметкой FAQPage, если есть FAQ.','Источники — нумерованным списком в конце.','Дисклеймер в подвале статьи.'],
    output_contract:{schema_version:'1.0',required:['article.detail_html'],optional:['article.name','article.code','article.seo_title','article.meta_description','article.preview_text','article.short_answer','article.sources','article.related_articles','used_blocks','warnings']}
  };
  APP_STATE.layout.task_id=taskId;
  APP_STATE.layout.source_hash=sourceHash;
  APP_STATE.layout.generated_at=new Date().toISOString();
  APP_STATE.layout.parsed_result=null;
  document.getElementById('layout_task').value=JSON.stringify(task,null,2);
  const status=document.getElementById('layout_result_status');
  if(status)status.textContent='Задание сформировано. Source hash: '+sourceHash+'. Вставьте ответ внешнего ИИ в поле результата.';
  logAction('Сформировано задание на вёрстку',{task_id:taskId,source_hash:sourceHash,template:d.layout_template,chars:html.length});
}

function stripHtmlToText(html){const doc=new DOMParser().parseFromString(String(html||''),'text/html');doc.querySelectorAll('script,style,iframe,object,embed').forEach(el=>el.remove());return (doc.body?.textContent||'').replace(/\s+/g,' ').trim();}
function extractNumbers(text){return (String(text||'').match(/\d+(?:[,.]\d+)?\s*(?:%|мм|см|мг|г|кг|мл|л|дн(?:я|ей)?|нед(?:ели|ель)?|мес(?:яцев|\.)?|лет|час(?:а|ов)?|мин(?:ут)?\b)?/gi)||[]).map(x=>x.replace(/\s+/g,' ').replace(',', '.').trim().toLowerCase()).sort();}
function normalizeLayoutRaw(raw){let text=String(raw||'').trim();const m=text.match(/^```(?:json)?\s*([\s\S]*?)\s*```$/i);if(m)text=m[1].trim();return text;}
function inspectLayoutHtml(html){const errors=[],warnings=[];let clean=String(html||'').trim();if(!clean)errors.push('Пустой detail_html.');if(/<\s*(script|iframe|object|embed)\b/i.test(clean))errors.push('HTML содержит запрещённые теги script/iframe/object/embed.');if(/\son[a-z]+\s*=/i.test(clean))errors.push('HTML содержит inline-обработчики событий.');if(/javascript\s*:/i.test(clean))errors.push('HTML содержит javascript: ссылку.');if(/<\s*(html|head|body)\b/i.test(clean)){warnings.push('Получен полный HTML-документ; будет использовано содержимое body.');const doc=new DOMParser().parseFromString(clean,'text/html');clean=doc.body?.innerHTML?.trim()||clean;}const text=stripHtmlToText(clean);if(text.length<20)errors.push('HTML не содержит содержательного текста.');if(text.length>3000&&!/<h2\b/i.test(clean))warnings.push('В длинной статье нет заголовков h2.');const original=currentArticleObject();const oldText=stripHtmlToText(original.detail_html);if(oldText.length){if(text.length<oldText.length*0.9)warnings.push('Новая версия короче исходной более чем на 10%.');if(text.length>oldText.length*1.25)warnings.push('Новая версия длиннее исходной более чем на 25%.');const oldNums=extractNumbers(oldText).join('|');const newNums=extractNumbers(text).join('|');if(oldNums!==newNums)warnings.push('Обнаружено изменение числовых значений или единиц измерения.');}const hadSources=Array.isArray(original.sources)?original.sources.length:String(fieldValue('result_sources')).trim().length;if(hadSources&&!/источник|source|literature|pubmed|doi|https?:\/\//i.test(clean))warnings.push('В исходной статье были источники, но в HTML они не обнаружены.');return {html:clean,errors,warnings,text_chars:text.length};}
function parseExternalLayoutResult(rawText){const rawHashSource=String(rawText||'');let text=normalizeLayoutRaw(rawHashSource);const warnings=[],errors=[];let data=null,fallback_html=false;if(!text){return {valid:false,article:{},warnings,errors:['Поле результата пустое.'],task_id:'',source_hash:'',fallback_html:false,raw_text:rawHashSource};}try{data=JSON.parse(text);}catch(e){if(/^\s*</.test(text)){fallback_html=true;warnings.push('Результат распознан как чистый HTML, а не JSON. Применение потребует подтверждения.');data={article:{detail_html:text}};}else{return {valid:false,article:{},warnings,errors:['Некорректный JSON: '+e.message],task_id:'',source_hash:'',fallback_html:false,raw_text:rawHashSource};}}
  const src=data.article&&typeof data.article==='object'?data.article:data;const article={};['name','code','seo_title','meta_description','preview_text','short_answer','sources','related_articles'].forEach(k=>{if(src[k]!==undefined)article[k]=src[k];});article.detail_html=String(src.detail_html||data.detail_html||data.html||'').trim();if(!article.detail_html)errors.push('В ответе нет обязательного article.detail_html.');const htmlCheck=inspectLayoutHtml(article.detail_html);article.detail_html=htmlCheck.html;warnings.push(...(Array.isArray(data.warnings)?data.warnings:[]),...htmlCheck.warnings);errors.push(...htmlCheck.errors);return {valid:errors.length===0,article,warnings,errors,task_id:data.task_id||'',source_hash:data.source_hash||'',used_blocks:data.used_blocks||[],fallback_html,raw_text:rawHashSource,html_chars:article.detail_html.length};}
function setLayoutStatus(lines){const el=document.getElementById('layout_result_status');if(el)el.textContent=Array.isArray(lines)?lines.join('\n'):String(lines||'');}
function setLayoutPreviewButtonEnabled(enabled){const btn=document.getElementById('preview_layout_result_button');if(btn){btn.disabled=!enabled;btn.setAttribute('aria-disabled',enabled?'false':'true');}}

function layoutResultChangedAfterValidation(parsed){return !parsed||!parsed.input_hash||parsed.input_hash!==APP_STATE.layout.last_input_hash;}
function buildLayoutPreviewDocument(parsedResult){
  const article=parsedResult.article||{};
  const title=escapeHtml(article.name||fieldValue('result_name')||'');
  const preview=escapeHtml(article.preview_text||fieldValue('result_preview')||'');
  const detailHtml=String(article.detail_html||'');
  return `<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${title}</title>
  <style>
    :root{color:#223025;background:#f4f7ee;font-family:Inter,Arial,sans-serif;line-height:1.65}body{margin:0;background:#f4f7ee}.article-preview{max-width:840px;margin:0 auto;padding:48px 24px 72px;background:#fff;box-shadow:0 10px 30px rgba(28,42,33,.08)}.article-preview__head{border-bottom:1px solid #dfe5df;margin-bottom:32px;padding-bottom:24px}h1{font-size:42px;line-height:1.12;margin:0 0 18px;color:#19342d}h2{font-size:30px;line-height:1.22;margin:42px 0 16px;color:#19342d}h3{font-size:23px;margin:30px 0 12px;color:#19342d}p,li{font-size:18px}a{color:#007f75}table{width:100%;border-collapse:collapse;margin:24px 0;font-size:16px}th,td{border:1px solid #d7dfd8;padding:12px;text-align:left;vertical-align:top}th{background:#eef7f0}blockquote,.note,.attention,.short-answer{border-left:5px solid #8bbf3f;background:#f3f8ea;padding:16px 18px;margin:24px 0;border-radius:10px}nav,.toc{background:#f7faf7;border:1px solid #dfe5df;border-radius:14px;padding:18px;margin:24px 0}.faq,.faq-item{border-top:1px solid #dfe5df;padding-top:16px;margin-top:16px}.article-preview__lead{font-size:21px;color:#4f6255}.form-placeholder,[data-form],form{display:block;border:1px dashed #9eb3a3;background:#f7faf7;border-radius:14px;padding:18px;margin:24px 0;color:#526557}img{max-width:100%;height:auto}@media(max-width:600px){.article-preview{padding:28px 16px}h1{font-size:32px}h2{font-size:25px}p,li{font-size:16px}}
  </style>
</head>
<body>
  <main class="article-preview">
    <header class="article-preview__head">
      <h1>${title}</h1>
      ${preview?`<p class="article-preview__lead">${preview}</p>`:''}
    </header>
    <article class="article-preview__content">${detailHtml}</article>
  </main>
</body>
</html>`;
}
function setLayoutPreviewWidth(width){const frame=document.getElementById('layout_preview_frame');if(frame){frame.style.width=width+'px';frame.dataset.width=String(width);}}
function closeLayoutPreview(){const modal=document.getElementById('layout_preview_modal');const frame=document.getElementById('layout_preview_frame');if(frame)frame.srcdoc='';modal?.classList.add('is-hidden');APP_STATE.layout.preview_opener?.focus?.();APP_STATE.layout.preview_opener=null;}
async function previewExternalLayoutResult(opener){const parsed=APP_STATE.layout.parsed_result;if(!parsed||!parsed.valid){alert('Сначала успешно проверьте результат вёрстки.');return;}const rawHash=await browserHash(fieldValue('external_layout_result'));if(!parsed.input_hash||parsed.input_hash!==rawHash){alert('Результат изменён после проверки. Сначала повторно нажмите «Проверить результат».');return;}if((parsed.errors||[]).length){alert('Предпросмотр заблокирован: есть ошибки проверки.');return;}const safety=inspectLayoutHtml(parsed.article.detail_html);if(safety.errors.length){alert('Предпросмотр заблокирован:\n'+safety.errors.join('\n'));return;}const modal=document.getElementById('layout_preview_modal');const frame=document.getElementById('layout_preview_frame');const meta=document.getElementById('layout_preview_meta');if(!modal||!frame)return;const hashStatus=parsed.source_hash&&APP_STATE.layout.source_hash?(parsed.source_hash===APP_STATE.layout.source_hash?'source_hash совпадает':'source_hash не совпадает'):(parsed.source_hash?'source_hash без последнего задания':'source_hash отсутствует');const applied=APP_STATE.layout.applied_result_hash?'есть применённый результат':'результат ещё не применён';if(meta)meta.textContent='Шаблон: '+(fieldValue('layout_template')||'default')+' · '+String(parsed.html_chars||0).replace(/\B(?=(\d{3})+(?!\d))/g,' ')+' символов · '+(parsed.warnings||[]).length+' предупреждения · '+hashStatus+' · '+applied;frame.srcdoc=buildLayoutPreviewDocument(parsed);setLayoutPreviewWidth(1200);APP_STATE.layout.preview_opener=opener||document.activeElement;modal.classList.remove('is-hidden');document.getElementById('layout_preview_close')?.focus();logAction('Открыт предпросмотр вёрстки',{task_id:parsed.task_id,source_hash:parsed.source_hash,template:fieldValue('layout_template')||'default',chars:parsed.html_chars||0,warnings:(parsed.warnings||[]).length});}
async function validateExternalLayoutResult(){const raw=fieldValue('external_layout_result');const parsed=parseExternalLayoutResult(raw);parsed.input_hash=await browserHash(raw);parsed.current_source_hash=await calculateLayoutSourceHash();APP_STATE.layout.last_input_hash=parsed.input_hash;APP_STATE.layout.parsed_result=parsed;const lines=parsed.valid?['Результат распознан.','HTML: '+String(parsed.html_chars||0).replace(/\B(?=(\d{3})+(?!\d))/g,' ')+' символов.','Предупреждений: '+parsed.warnings.length+'.','Можно применить после проверки.']:['Результат не прошёл проверку.'];parsed.warnings.forEach(w=>lines.push('Предупреждение: '+w));parsed.errors.forEach(e=>lines.push('Ошибка: '+e));if(parsed.source_hash&&APP_STATE.layout.source_hash&&parsed.source_hash!==APP_STATE.layout.source_hash)lines.push('Предупреждение: source_hash не совпадает с последним заданием.');if(parsed.source_hash&&parsed.current_source_hash&&parsed.source_hash!==parsed.current_source_hash)lines.push('Предупреждение: исходная статья изменилась после формирования задания.');if(!parsed.source_hash)lines.push('Предупреждение: source_hash отсутствует, результат нельзя однозначно связать с последним заданием.');setLayoutPreviewButtonEnabled(parsed.valid);setLayoutStatus(lines);logAction('Результат вёрстки проверен',{task_id:parsed.task_id,source_hash:parsed.source_hash,chars:parsed.html_chars||0,warnings:parsed.warnings.length,valid:parsed.valid});return parsed;}
function nonEmpty(v){return Array.isArray(v)?v.length>0:String(v??'').trim().length>0;}
async function applyExternalLayoutResult(){let parsed=APP_STATE.layout.parsed_result;if(!parsed){parsed=await validateExternalLayoutResult();}const raw=fieldValue('external_layout_result');const inputHash=await browserHash(raw);if(!parsed.input_hash||parsed.input_hash!==inputHash){alert('Поле результата изменилось после проверки. Проверьте результат повторно.');return;}if(!parsed.valid){alert('Нельзя применить результат: есть ошибки проверки.');return;}const resultHash=await browserHash(JSON.stringify(parsed.article));if(APP_STATE.layout.applied_result_hash===resultHash&&!confirm('Этот результат вёрстки уже применён. Применить повторно?'))return;const currentSourceHash=await calculateLayoutSourceHash();if(parsed.source_hash&&currentSourceHash&&parsed.source_hash!==currentSourceHash&&!confirm('source_hash результата не совпадает с текущей итоговой статьёй. Возможно, результат устарел. Всё равно применить?'))return;if(parsed.source_hash&&APP_STATE.layout.source_hash&&parsed.source_hash!==APP_STATE.layout.source_hash&&!confirm('source_hash результата не совпадает с последним заданием. Всё равно применить?'))return;if(!parsed.source_hash&&!confirm('source_hash отсутствует, результат нельзя однозначно связать с последним заданием. Всё равно применить?'))return;if(parsed.fallback_html&&!confirm('Результат распознан как чистый HTML, а не JSON. Всё равно применить?'))return;const oldHtml=fieldValue('result_detail_html');if(!confirm('Применить результат вёрстки?\n\nСтарый HTML: '+oldHtml.length+' символов.\nНовый HTML: '+parsed.article.detail_html.length+' символов.'))return;APP_STATE.layout.previous_article={result_name:fieldValue('result_name'),result_code:fieldValue('result_code'),result_seo_title:fieldValue('result_seo_title'),result_meta_description:fieldValue('result_meta_description'),result_preview:fieldValue('result_preview'),result_short_answer:fieldValue('result_short_answer'),result_detail_html:oldHtml,result_sources:fieldValue('result_sources'),result_related_articles:fieldValue('result_related_articles')};setFieldValue('result_detail_html',parsed.article.detail_html);const map={name:'result_name',code:'result_code',seo_title:'result_seo_title',meta_description:'result_meta_description',preview_text:'result_preview',short_answer:'result_short_answer',sources:'result_sources',related_articles:'result_related_articles'};Object.entries(map).forEach(([k,id])=>{if(nonEmpty(parsed.article[k]))setFieldValue(id,Array.isArray(parsed.article[k])?valueToText(parsed.article[k]):parsed.article[k]);});APP_STATE.layout.applied_result_hash=resultHash;await markUniquenessOutdated();setLayoutStatus(['Результат вёрстки применён.','Теперь XML будет сформирован из обновлённой версии статьи.']);logAction('Результат вёрстки применён',{task_id:parsed.task_id,source_hash:parsed.source_hash,chars:parsed.article.detail_html.length,warnings:parsed.warnings.length});}
function clearExternalLayoutResult(){setFieldValue('external_layout_result','');APP_STATE.layout.parsed_result=null;setLayoutPreviewButtonEnabled(false);setLayoutStatus('Результат вёрстки ещё не загружен.');}
function restorePreLayoutArticle(){const prev=APP_STATE.layout.previous_article;if(!prev){alert('Нет сохранённой версии для отката в текущей сессии.');return;}Object.entries(prev).forEach(([id,value])=>setFieldValue(id,value));markUniquenessOutdated();setLayoutStatus('Предыдущая версия статьи восстановлена. XML снова будет сформирован из восстановленной итоговой версии.');logAction('Выполнен откат применённой вёрстки',{task_id:APP_STATE.layout.task_id,source_hash:APP_STATE.layout.source_hash});}


/* ---- копирование ---- */
document.querySelectorAll('[data-copy]').forEach(btn=>{
  btn.addEventListener('click',()=>{
    const el=document.getElementById(btn.dataset.copy);
    const text=('value'in el&&el.tagName!=='PRE')?el.value:el.textContent;
    if(!text||!text.trim()){alert('Нечего копировать — поле пустое.');return;}
    const done=()=>{const old=btn.textContent;btn.textContent='Скопировано ✓';setTimeout(()=>btn.textContent=old,1500);logAction('Скопировано в буфер: '+btn.dataset.copy,{chars:text.length});};
    if(navigator.clipboard&&navigator.clipboard.writeText){
      navigator.clipboard.writeText(text).then(done).catch(()=>fallbackCopy(text,done));
    }else fallbackCopy(text,done);
  });
});
function fallbackCopy(text,done){
  const ta=document.createElement('textarea');ta.value=text;document.body.appendChild(ta);
  ta.select();try{document.execCommand('copy');done();}catch(e){alert('Не удалось скопировать — выделите и скопируйте вручную.');}
  document.body.removeChild(ta);
}


/* ===================== Assistant, uniqueness and XML export ===================== */
const APP_STATE=window.APP_STATE||{assistant:{mode:'article',sessionId:'',messages:[]},uniqueness:{internal:null,external:null},layout:{task_id:'',source_hash:'',generated_at:'',parsed_result:null,previous_article:null,applied_result_hash:'',last_input_hash:'',preview_opener:null}};
APP_STATE.layout=APP_STATE.layout||{task_id:'',source_hash:'',generated_at:'',parsed_result:null,previous_article:null,applied_result_hash:'',last_input_hash:'',preview_opener:null};
window.APP_STATE=APP_STATE;
const ASSISTANT_ALLOWED_FIELDS=new Set(['topic','primary_query','secondary_queries','search_intent','article_structure','article_type','region','reader_goal','result_name','result_preview','result_short_answer','result_detail_html','revision_request']);
function fieldValue(id){const el=document.getElementById(id);return el?el.value:'';}
function setFieldValue(id,value){const el=document.getElementById(id);if(!el)return false;el.value=String(value??'');el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));return true;}
function collectUniquenessText(){return {source:fieldValue('existing_article_source')||'new',element_id:fieldValue('existing_article_id')||null,name:fieldValue('result_name'),preview_text:fieldValue('result_preview'),short_answer:fieldValue('result_short_answer'),detail_html:fieldValue('result_detail_html')};}
async function browserHash(value){if(!crypto.subtle)return String(value.length);const data=new TextEncoder().encode(value);const hash=await crypto.subtle.digest('SHA-256',data);return Array.from(new Uint8Array(hash)).map(b=>b.toString(16).padStart(2,'0')).join('');}
async function collectExternalUniquenessText(){return collectExternalUniquenessTextSync();}
function collectExternalUniquenessTextSync(){const article=collectUniquenessText();const raw=[article.name,article.preview_text,article.short_answer,article.detail_html].join('\n\n');const doc=new DOMParser().parseFromString(String(raw),'text/html');doc.querySelectorAll('script,style,iframe,form').forEach(el=>el.remove());return (doc.body?.textContent||'').replace(/\s+/g,' ').trim();}
async function calculateExternalUniquenessHash(){return browserHash(collectExternalUniquenessTextSync());}
async function currentUniquenessHash(){return calculateExternalUniquenessHash();}
async function markUniquenessOutdated(){await markExternalUniquenessOutdated();let changed=false;const h=await currentUniquenessHash();const r=APP_STATE.uniqueness.internal;if(r&&r.status==='completed'&&r.client_hash&&r.client_hash!==h){r.status='outdated';changed=true;}if(changed){document.getElementById('uniqueness_outdated_warning')?.classList.remove('is-hidden');renderInternalUniqueness();}}
function markExternalUniquenessOutdated(){return (async()=>{const h=await calculateExternalUniquenessHash();const r=APP_STATE.uniqueness.external;if(r&&['completed','processing','queued'].includes(r.status)&&r.content_hash&&r.content_hash!==h){r.status='outdated';stopExternalUniquenessPoll();sessionStorage.removeItem('temed_external_uniqueness');document.getElementById('uniqueness_outdated_warning')?.classList.remove('is-hidden');renderExternalUniqueness();}})();}
function statusLabel(status){return {not_started:'Не запускалась',queued:'Отправлено на проверку',processing:'Проверяется',completed:'Уникальность',failed:'Ошибка',outdated:'Результат устарел'}[status]||status;}
function renderUniqueness(targetId,result){const box=document.getElementById(targetId);if(!box)return;const status=result?.status||'not_started';box.dataset.status=status;const statusEl=box.querySelector('.status');const matches=box.querySelector('.matches');if(statusEl){if(status==='completed'&&result.uniqueness_percent!==undefined){statusEl.textContent=`Уникальность ${Number(result.uniqueness_percent).toFixed(2).replace(/\.00$/,'')}%${result.checked_at?' · '+result.checked_at:''}`;}else if(status==='failed'){statusEl.textContent=`Ошибка${result.message?': '+result.message:''}`;}else if(status==='outdated'){statusEl.textContent='Результат устарел';}else if(status==='queued'){statusEl.textContent='Отправлено на проверку';}else{statusEl.textContent=statusLabel(status);}}
if(matches){matches.textContent='';const urls=result?.urls||result?.result?.urls||result?.matches||[];(Array.isArray(urls)?urls:[]).forEach(item=>{const div=document.createElement('div');div.className='match-item';const url=item.url||item.link||item.domain||'';if(url){const link=document.createElement('a');link.href=/^https?:\/\//i.test(url)?url:'https://'+url;link.target='_blank';link.rel='noopener noreferrer';link.textContent=url;div.appendChild(link);}else{div.textContent=item.name||'Совпадение';}const pct=document.createElement('span');pct.textContent=' '+(item.matched_percent??item.plagiat??item.percent??item.part??0)+'%';div.appendChild(pct);matches.appendChild(div);});const seo=result?.seo_check||{};['water_percent','spam_percent','water','spam'].forEach(k=>{if(seo[k]!==undefined){const div=document.createElement('div');div.className='match-item';div.textContent=`${k}: ${seo[k]}`;matches.appendChild(div);}});(result?.warnings||[]).forEach(w=>{const div=document.createElement('div');div.className='match-item';div.textContent='Предупреждение: '+w;matches.appendChild(div);});}}
function renderInternalUniqueness(){renderUniqueness('internal_uniqueness_result',APP_STATE.uniqueness.internal);}function renderExternalUniqueness(){renderUniqueness('external_uniqueness_result',APP_STATE.uniqueness.external);}
async function runInternalUniqueness(button){setButtonBusy(button,true);try{const client_hash=await currentUniquenessHash();APP_STATE.uniqueness.internal={status:'processing',client_hash};renderInternalUniqueness();const response=await fetch('proxy.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action:'check_internal_uniqueness',article:collectUniquenessText(),existing_article_source:fieldValue('existing_article_source')||'new',existing_article_id:fieldValue('existing_article_id')||null})});const payload=await response.json();if(!response.ok||payload.success!==true)throw new Error(payload.message||payload.error||'Ошибка проверки');APP_STATE.uniqueness.internal={...payload.data,client_hash};document.getElementById('uniqueness_outdated_warning')?.classList.add('is-hidden');renderInternalUniqueness();logAction('Внутренняя уникальность проверена',payload.data);}catch(e){APP_STATE.uniqueness.internal={status:'failed',message:String(e.message||e)};renderInternalUniqueness();alert(String(e.message||e));}finally{setButtonBusy(button,false);}}
function readExternalUniquenessState(){try{const saved=JSON.parse(sessionStorage.getItem('temed_external_uniqueness')||'null');return saved&&typeof saved==='object'?saved:null;}catch(_){sessionStorage.removeItem('temed_external_uniqueness');return null;}}
function hasExternalUid(state){return !!(state&&typeof state.text_uid==='string'&&state.text_uid.trim());}
function isActiveExternalStatus(status){return ['queued','processing'].includes(status);}
function removeBrokenExternalState(saved){sessionStorage.removeItem('temed_external_uniqueness');logAction('Удалено повреждённое состояние TEXT.RU',{reason:'missing_text_uid',content_hash:saved?.content_hash||''});}
function saveExternalUniquenessState(state){APP_STATE.uniqueness.external=state;sessionStorage.setItem('temed_external_uniqueness',JSON.stringify(state));}
async function startExternalUniqueness(button){setButtonBusy(button,true);try{const text=collectExternalUniquenessTextSync();if(text.length<100)throw new Error('Для проверки TEXT.RU требуется не менее 100 символов.');const content_hash=await calculateExternalUniquenessHash();const saved=readExternalUniquenessState();if(saved&&isActiveExternalStatus(saved.status)&&!hasExternalUid(saved)){removeBrokenExternalState(saved);}else if(saved&&saved.content_hash===content_hash&&hasExternalUid(saved)&&isActiveExternalStatus(saved.status)){APP_STATE.uniqueness.external={...saved,text_uid:saved.text_uid.trim()};renderExternalUniqueness();scheduleExternalUniquenessPoll();return;}APP_STATE.uniqueness.external={status:'queued',content_hash,started_at:new Date().toISOString(),attempt:0};renderExternalUniqueness();const data=await callN8n('start_external_uniqueness',{text,content_hash},button);if(!data||typeof data!=='object'||typeof data.text_uid!=='string'||!data.text_uid.trim()){throw new Error('TEXT.RU не вернул идентификатор проверки text_uid.');}data.text_uid=data.text_uid.trim();saveExternalUniquenessState({...data,attempt:0,started_at:data.submitted_at||new Date().toISOString(),status:data.status||'processing'});renderExternalUniqueness();scheduleExternalUniquenessPoll();}catch(e){APP_STATE.uniqueness.external={status:'failed',message:String(e.message||e)};renderExternalUniqueness();alert(String(e.message||e));}finally{setButtonBusy(button,false);}}
let externalPollTimer=null;function stopExternalUniquenessPoll(){if(externalPollTimer){clearTimeout(externalPollTimer);externalPollTimer=null;}}
function scheduleExternalUniquenessPoll(){stopExternalUniquenessPoll();const state=APP_STATE.uniqueness.external;if(!state||!hasExternalUid(state)||!isActiveExternalStatus(state.status))return;const attempt=Number(state.attempt||0);if(attempt>=40){saveExternalUniquenessState({...state,status:'failed',message:'Проверка TEXT.RU всё ещё выполняется. Результат можно запросить повторно позднее.'});renderExternalUniqueness();return;}const delaySeconds=Math.max(5,Number(state.retry_after_seconds)||(attempt===0?10:15));externalPollTimer=setTimeout(pollExternalUniqueness,delaySeconds*1000);}
function isTemporaryExternalError(error){const message=String(error?.message||error||'').toLowerCase();return /\b(429|502|503|504)\b/.test(message)||message.includes('timeout')||message.includes('temporar')||message.includes('времен');}
async function pollExternalUniqueness(){const state=APP_STATE.uniqueness.external;if(!state||!hasExternalUid(state)||!isActiveExternalStatus(state.status))return;const currentHash=await calculateExternalUniquenessHash();if(state.content_hash!==currentHash){await markExternalUniquenessOutdated();return;}try{const data=await callN8n('get_external_uniqueness',{text_uid:state.text_uid,content_hash:state.content_hash},null);if(!data||typeof data!=='object'){throw new Error('TEXT.RU вернул некорректный ответ.');}const responseUid=typeof data.text_uid==='string'&&data.text_uid.trim()?data.text_uid.trim():state.text_uid;if(data.content_hash&&data.content_hash!==currentHash){APP_STATE.uniqueness.external={...state,...data,text_uid:responseUid,status:'outdated'};sessionStorage.removeItem('temed_external_uniqueness');renderExternalUniqueness();return;}const next={...state,...data,text_uid:responseUid,content_hash:data.content_hash||state.content_hash,started_at:state.started_at,attempt:Number(state.attempt||0)+1,status:data.status||'processing'};saveExternalUniquenessState(next);renderExternalUniqueness();if(next.status==='completed'){stopExternalUniquenessPoll();logAction('Проверка TEXT.RU завершена',{text_uid:next.text_uid,uniqueness_percent:next.uniqueness_percent});}else if(isActiveExternalStatus(next.status)){scheduleExternalUniquenessPoll();}else{stopExternalUniquenessPoll();}}catch(e){if(isTemporaryExternalError(e)&&Number(state.attempt||0)<40){saveExternalUniquenessState({...state,attempt:Number(state.attempt||0)+1,retry_after_seconds:Math.max(15,Number(state.retry_after_seconds||0)||15),message:String(e.message||e)});renderExternalUniqueness();scheduleExternalUniquenessPoll();return;}saveExternalUniquenessState({...state,status:'failed',message:String(e.message||e)});stopExternalUniquenessPoll();renderExternalUniqueness();}}
function collectAssistantContext(){return {mode:APP_STATE.assistant.mode,message:fieldValue('assistant_input'),current_step:Number(document.querySelector('.step.on')?.dataset.step||0),article_context:{workflow_mode:fieldValue('workflow_mode'),task_name:fieldValue('task_name'),topic:fieldValue('topic'),primary_query:fieldValue('primary_query'),secondary_queries:fieldValue('secondary_queries'),search_intent:fieldValue('search_intent'),article_structure:fieldValue('article_structure'),article_type:fieldValue('article_type'),region:fieldValue('region'),reader_goal:fieldValue('reader_goal'),author_id:fieldValue('author_id'),medical_reviewer_id:fieldValue('medical_reviewer_id'),clinic_id:fieldValue('clinic_id'),service_id:fieldValue('service_id'),related_articles:[],generated_outline:fieldValue('generated_outline').slice(0,100000),article:currentArticleObject(),uniqueness:APP_STATE.uniqueness},empty_required_fields:[],conversation:APP_STATE.assistant.messages.slice(-20)}}
function renderAssistantMessage(role,text){const list=document.getElementById('assistant_messages');if(!list)return;const div=document.createElement('div');div.className='assistant-message '+role;div.textContent=text;list.appendChild(div);list.scrollTop=list.scrollHeight;}
function renderAssistantSources(sources){const box=document.getElementById('assistant_sources');if(!box)return;box.textContent='';(sources||[]).forEach(src=>{const chip=document.createElement('span');chip.className='source-chip';chip.textContent=[src.type,src.path||src.action,src.section].filter(Boolean).join(' · ');box.appendChild(chip);});}
function renderAssistantSuggestions(items){const box=document.getElementById('assistant_suggestions');if(!box)return;box.textContent='';(items||[]).forEach(s=>{const card=document.createElement('div');card.className='suggestion-card';const text=document.createElement('div');text.textContent=`Поле: ${s.field_id}. Предлагается: ${s.label||s.value}. Причина: ${s.reason||''}`;const btn=document.createElement('button');btn.type='button';btn.className='btn';btn.textContent='Применить';btn.addEventListener('click',()=>applyAssistantSuggestion(s));card.append(text,btn);box.appendChild(card);});}
function applyAssistantSuggestion(s){if(!s||!ASSISTANT_ALLOWED_FIELDS.has(s.field_id)){alert('Ассистент предложил неизвестное или запрещённое поле.');return;}const el=document.getElementById(s.field_id);if(!el||el.readOnly){alert('Поле нельзя изменить.');return;}if(el.tagName==='SELECT'&&s.value&&!Array.from(el.options).some(o=>o.value===String(s.value))){alert('Недопустимое значение списка.');return;}const old=el.value;if(!confirm(`Применить предложение?\n\nПоле: ${s.field_id}\nТекущее значение: ${old||'—'}\nПредлагается: ${s.value||'—'}`))return;setFieldValue(s.field_id,s.value);logAction('Применено предложение ассистента',{field_id:s.field_id,old,new:s.value});if(['result_name','result_preview','result_short_answer','result_detail_html'].includes(s.field_id))markUniquenessOutdated();}
async function sendAssistantMessage(){const input=document.getElementById('assistant_input');const message=input?.value.trim();if(!message)return;APP_STATE.assistant.messages.push({role:'user',content:message});APP_STATE.assistant.messages=APP_STATE.assistant.messages.slice(-20);renderAssistantMessage('user',message);input.value='';try{const data=await callN8n('assistant_chat',collectAssistantContext(),document.getElementById('assistant_send'));if(!data||typeof data!=='object'){throw new Error('Ассистент вернул некорректный ответ.');}const answer=typeof data.answer==='string'&&data.answer.trim()?data.answer.trim():'Ассистент не вернул текст ответа.';APP_STATE.assistant.messages.push({role:'assistant',content:answer});APP_STATE.assistant.messages=APP_STATE.assistant.messages.slice(-20);sessionStorage.setItem('temed_assistant_'+(fieldValue('generation_id')||'draft'),JSON.stringify(APP_STATE.assistant.messages));renderAssistantMessage('assistant',answer);renderAssistantSources(data.sources);renderAssistantSuggestions(data.suggestions);}catch(e){renderAssistantMessage('assistant','Ошибка ассистента: '+String(e.message||e));}}
const XML_REQUIRED_FIELD_LABELS={name:'Название статьи',code:'Символьный код URL',detail_html:'Полный текст статьи',article_structure:'Структура статьи',article_structure_version:'Версия структуры статьи — повторно выберите структуру статьи',search_intent:'Поисковый интент',article_type:'Тип статьи',author_id:'Автор',medical_reviewer_id:'Проверивший врач',section:'Раздел статьи'};
const XML_FIELD_TARGETS={name:'result_name',code:'result_code',detail_html:'result_detail_html',article_type:'article_type',author_id:'author_id',medical_reviewer_id:'medical_reviewer_id',section:'article_section_id'};
const XML_SEARCH_TASK_FIELDS=new Set(['article_structure','article_structure_version','search_intent']);
function xmlExportSectionValue(){const sectionId=fieldValue('article_section_id');return sectionId==='__new__'?fieldValue('article_section'):(sectionId||fieldValue('article_section'));}
function bitrixXmlPayload(){const article=currentArticleObject();return {...article,name:article.name,preview_text:article.preview_text,detail_html:article.detail_html,section:xmlExportSectionValue(),article_structure:fieldValue('article_structure'),article_structure_name:fieldValue('article_structure_name'),article_structure_version:fieldValue('article_structure_version'),search_intent:fieldValue('search_intent'),article_type:fieldValue('article_type'),author_id:fieldValue('author_id'),medical_reviewer_id:fieldValue('medical_reviewer_id'),region:fieldValue('region'),related_articles:article.related_articles};}
function validateXmlRequiredFields(payload){return Object.keys(XML_REQUIRED_FIELD_LABELS).filter(code=>!String(payload?.[code]??'').trim());}
function formatXmlMissingFieldsMessage(missingFields){const labels=missingFields.map(code=>XML_REQUIRED_FIELD_LABELS[code]||code);return `Не удалось сформировать XML.\n\nНе заполнены обязательные поля:\n• ${labels.join('\n• ')}\n\nПервое незаполненное поле выделено в редакторе.\n\nИсправьте указанные поля и повторите экспорт.`;}
function xmlFieldElement(code){if(code==='author_id')return document.getElementById('author_search')||document.getElementById('author_id');if(code==='medical_reviewer_id')return document.getElementById('medical_reviewer_search')||document.getElementById('medical_reviewer_id');if(code==='section'&&fieldValue('article_section_id')==='__new__')return document.getElementById('new_article_section')||document.getElementById('article_section_id');return document.getElementById(XML_FIELD_TARGETS[code]||code);}
function clearXmlFieldErrors(){document.querySelectorAll('.xml-field-error').forEach(el=>el.classList.remove('xml-field-error'));}
function markXmlFieldError(code){if(XML_SEARCH_TASK_FIELDS.has(code)){document.getElementById(code==='search_intent'?'intentCards':'structCards')?.classList.add('xml-field-error');return;}const el=xmlFieldElement(code);if(!el)return;el.classList.add('xml-field-error');}
function focusFirstXmlFieldError(missingFields){const first=missingFields[0];if(!first)return;if(XML_SEARCH_TASK_FIELDS.has(first)){goto(1);setTimeout(()=>{const target=document.getElementById(first==='search_intent'?'intentCards':'structCards');target?.scrollIntoView({behavior:'smooth',block:'center'});target?.querySelector('button:not(:disabled)')?.focus({preventScroll:true});},80);return;}const el=xmlFieldElement(first);const step=el?.closest('.step');if(step)goto(Number(step.dataset.step));setTimeout(()=>{el?.scrollIntoView({behavior:'smooth',block:'center'});if(el&&/^(INPUT|SELECT|TEXTAREA|BUTTON)$/.test(el.tagName)&&el.type!=='hidden')el.focus({preventScroll:true});},80);}
function handleXmlMissingFields(missingFields,source){clearXmlFieldErrors();missingFields.forEach(markXmlFieldError);focusFirstXmlFieldError(missingFields);const message=formatXmlMissingFieldsMessage(missingFields);setFieldValue('save_result',message);logAction('XML не сформирован: не заполнены обязательные поля',{source,missing_fields:missingFields});alert(message);}
async function downloadBitrixXml(button){setButtonBusy(button,true);try{const payload=bitrixXmlPayload();const clientMissing=validateXmlRequiredFields(payload);if(clientMissing.length){handleXmlMissingFields(clientMissing,'client');return;}const response=await fetch('export.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'application/xml, application/json'},body:JSON.stringify(payload)});const type=response.headers.get('Content-Type')||'';if(type.includes('application/json')){const err=await response.json();if(!response.ok||err.success===false){const missing=Array.isArray(err.details?.missing_fields)?err.details.missing_fields.filter(code=>XML_REQUIRED_FIELD_LABELS[code]):[];if(missing.length){handleXmlMissingFields(missing,'server');return;}throw new Error(err.message||'Ошибка формирования XML');}}else if(!response.ok){throw new Error('Ошибка HTTP '+response.status);}clearXmlFieldErrors();const blob=await response.blob();const disposition=response.headers.get('Content-Disposition')||'';const match=disposition.match(/filename="?([^";]+)"?/);const filename=match?match[1]:'bitrix-article.xml';const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download=filename;document.body.appendChild(a);a.click();a.remove();URL.revokeObjectURL(url);setFieldValue('save_result',`XML сформирован: ${filename}
Статус статьи в XML: неактивна`);logAction('XML сформирован',{filename});}catch(e){const message=String(e.message||e||'Ошибка формирования XML');setFieldValue('save_result',message);logAction('XML не сформирован',{error:message});alert(message);}finally{setButtonBusy(button,false);}}
['result_name','result_preview','result_short_answer','result_detail_html'].forEach(id=>document.getElementById(id)?.addEventListener('input',markUniquenessOutdated));
['result_name','result_code','result_detail_html','article_type','author_search','medical_reviewer_search','article_section_id','new_article_section'].forEach(id=>{const el=document.getElementById(id);['input','change'].forEach(eventName=>el?.addEventListener(eventName,()=>{el.classList.remove('xml-field-error');}));});
intentCards?.addEventListener('click',()=>intentCards.classList.remove('xml-field-error'));
structCards?.addEventListener('click',()=>structCards.classList.remove('xml-field-error'));
document.getElementById('assistant_toggle')?.addEventListener('click',()=>document.getElementById('assistant_panel')?.classList.toggle('open'));
document.getElementById('assistant_send')?.addEventListener('click',sendAssistantMessage);

document.getElementById('layout_preview_close')?.addEventListener('click',closeLayoutPreview);
document.getElementById('layout_preview_desktop')?.addEventListener('click',()=>setLayoutPreviewWidth(1200));
document.getElementById('layout_preview_tablet')?.addEventListener('click',()=>setLayoutPreviewWidth(768));
document.getElementById('layout_preview_mobile')?.addEventListener('click',()=>setLayoutPreviewWidth(390));
document.getElementById('layout_preview_apply')?.addEventListener('click',()=>applyExternalLayoutResult());
document.getElementById('layout_preview_modal')?.addEventListener('click',e=>{if(e.target?.id==='layout_preview_modal')closeLayoutPreview();});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!document.getElementById('layout_preview_modal')?.classList.contains('is-hidden'))closeLayoutPreview();});
document.getElementById('assistant_clear')?.addEventListener('click',()=>{APP_STATE.assistant.messages=[];sessionStorage.removeItem('temed_assistant_'+(fieldValue('generation_id')||'draft'));document.getElementById('assistant_messages').textContent='';});
document.getElementById('assistant_mode')?.querySelectorAll('button').forEach(btn=>btn.addEventListener('click',()=>{document.getElementById('assistant_mode').querySelectorAll('button').forEach(b=>b.classList.remove('on'));btn.classList.add('on');APP_STATE.assistant.mode=btn.dataset.mode||'article';}));
(async()=>{const saved=readExternalUniquenessState();if(!saved)return;if(isActiveExternalStatus(saved.status)&&!hasExternalUid(saved)){removeBrokenExternalState(saved);return;}if(!saved.status||!saved.content_hash||!hasExternalUid(saved)){sessionStorage.removeItem('temed_external_uniqueness');return;}const currentHash=await calculateExternalUniquenessHash();if(saved.content_hash!==currentHash){APP_STATE.uniqueness.external={...saved,status:'outdated'};sessionStorage.removeItem('temed_external_uniqueness');renderExternalUniqueness();return;}APP_STATE.uniqueness.external={...saved,text_uid:saved.text_uid.trim()};renderExternalUniqueness();if(isActiveExternalStatus(APP_STATE.uniqueness.external.status))scheduleExternalUniquenessPoll();})();

/* ===== Google Sheets / Drive drafts ===== */
const DRAFT_FORBIDDEN_FIELDS = new Set([
  'password','password_hash','cookie','csrf','session','php_session','secret','n8n_secret',
  'api_key','credentials','temed_seo_api_token','text_uid','config.php'
]);
const DRAFT_FIELD_IDS = Array.from(document.querySelectorAll('#articleForm input[id], #articleForm textarea[id], #articleForm select[id]'))
  .map(el=>el.id)
  .filter(id=>id && !DRAFT_FORBIDDEN_FIELDS.has(id) && id !== 'action_log');
const DRAFT_REQUIRED_COMMENT_REASONS = new Set(['before_medical_review','after_medical_review','needs_revision','restored_version']);
APP_STATE.draft = APP_STATE.draft || {draft_id:'',version_id:'',version_number:0,saved_hash:'',loaded_at:'',status:'',is_dirty:false};
APP_STATE.draftDictionaries = APP_STATE.draftDictionaries || {statuses:[],workflow_steps:[],save_reasons:[],raw:[]};
APP_STATE.drafts = APP_STATE.drafts || {items:[],offset:0,limit:50};

function stableStringify(value){
  if(value === undefined) return undefined;
  if(value === null || typeof value !== 'object') return JSON.stringify(value);
  if(Array.isArray(value)) return '[' + value.map(item => stableStringify(item) ?? 'null').join(',') + ']';
  return '{' + Object.keys(value).sort().map(key => {
    const item = stableStringify(value[key]);
    return item === undefined ? '' : JSON.stringify(key) + ':' + item;
  }).filter(Boolean).join(',') + '}';
}
async function draftSha256(value){return browserHash(typeof value === 'string' ? value : stableStringify(value));}
function assertNoForbiddenDraftFields(obj,path=''){
  if(!obj || typeof obj !== 'object') return;
  Object.keys(obj).forEach(key=>{
    const lower=key.toLowerCase();
    if(DRAFT_FORBIDDEN_FIELDS.has(lower)) throw new Error('Запрещённое поле в снимке: '+(path?path+'.':'')+key);
    assertNoForbiddenDraftFields(obj[key],(path?path+'.':'')+key);
  });
}
function collectDraftSnapshot(){
  const fields={};
  DRAFT_FIELD_IDS.forEach(id=>{fields[id]=fieldValue(id);});
  const editor_state={
    current_step:cur,
    fields,
    workflow:buildBrief().workflow,
    search_task:buildBrief().search_task,
    placement:buildBrief().placement,
    medical_roles:buildBrief().medical_roles,
    internal_context:buildBrief().internal_context,
    sources:buildBrief().sources,
    instructions:buildBrief().instructions,
    generated_outline:fieldValue('generated_outline'),
    article:currentArticleObject(),
    medical_review:{questions:fieldValue('med_questions'),answers:fieldValue('med_answers')},
    validation:{report:fieldValue('validation_report'),revision_request:fieldValue('revision_request')},
    layout:{notes:fieldValue('layout_notes'),task:fieldValue('layout_task'),external_result:fieldValue('external_layout_result'),state:{...APP_STATE.layout,preview_opener:null}},
    uniqueness:{internal:APP_STATE.uniqueness.internal,external:APP_STATE.uniqueness.external && APP_STATE.uniqueness.external.status === 'completed' ? {...APP_STATE.uniqueness.external,text_uid:undefined} : null},
    theme:localStorage.getItem('temed_seo_theme') || ''
  };
  assertNoForbiddenDraftFields(editor_state);
  return {schema_version:'1.0',draft_id:APP_STATE.draft.draft_id||'',version_id:APP_STATE.draft.version_id||'',version_number:APP_STATE.draft.version_number||0,saved_at:'',saved_by:'',save_comment:'',status:APP_STATE.draft.status||'',workflow_step:'',editor_state};
}
async function collectDraftPayload(){const snapshot=collectDraftSnapshot();snapshot.snapshot_hash=await draftSha256(snapshot.editor_state);return snapshot;}
function applyDraftSnapshot(snapshot){
  if(!snapshot || snapshot.schema_version !== '1.0' || !snapshot.editor_state) throw new Error('Некорректный формат снимка черновика.');
  const state=snapshot.editor_state;
  assertNoForbiddenDraftFields(state);
  Object.entries(state.fields||{}).forEach(([id,value])=>{if(DRAFT_FIELD_IDS.includes(id)) setFieldValue(id,value);});
  if(state.article) setArticleResult(state.article);
  APP_STATE.layout={...APP_STATE.layout,...(state.layout?.state||{}),preview_opener:null};
  APP_STATE.uniqueness.internal=state.uniqueness?.internal||null;
  APP_STATE.uniqueness.external=state.uniqueness?.external && state.uniqueness.external.status === 'completed' ? state.uniqueness.external : null;
  stopExternalUniquenessPoll(); sessionStorage.removeItem('temed_external_uniqueness'); renderInternalUniqueness(); renderExternalUniqueness();
  if(state.search_task?.search_intent){document.getElementById('search_intent').value=state.search_task.search_intent;renderStructs(state.search_task.search_intent);}
  if(state.search_task?.article_structure) selectStruct(state.search_task.article_structure);
  goto(Math.max(0,Math.min(13,Number(state.current_step||0))));
  document.getElementById('topTaskName').textContent=fieldValue('task_name')||fieldValue('result_name')||'Новая задача';
  logAction('Загружен снимок черновика',{draft_id:snapshot.draft_id||'',version_id:snapshot.version_id||'',version_number:snapshot.version_number||0});
}
function draftOptionLabel(item){return item.label || item.name || item.title || item.value || item.code || ''}
function dictionaryByType(type){return (APP_STATE.draftDictionaries.raw||[]).filter(item=>String(item.type||item.TYPE||'').toLowerCase()===type)}
function fillSelect(select,items,placeholder){if(!select)return;const current=select.value;select.innerHTML='<option value="">'+escapeHtml(placeholder||'—')+'</option>';items.forEach(item=>{const o=document.createElement('option');o.value=String(item.code||item.CODE||item.value||item.VALUE||item.id||item.ID||'');o.textContent=draftOptionLabel(item)||o.value;select.appendChild(o);});if([...select.options].some(o=>o.value===current))select.value=current;}
function fillDoctorSelect(select,placeholder){if(!select)return;const current=select.value;select.innerHTML='<option value="">'+escapeHtml(placeholder||'—')+'</option>';APP_DATA.doctors.forEach(d=>{const o=document.createElement('option');o.value=String(d.id);o.textContent=d.name||String(d.id);select.appendChild(o);});if([...select.options].some(o=>o.value===current))select.value=current;}
async function callDraftsApi(action,data={}){
  const response=await fetch('drafts.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action,data})});
  const text=await response.text();let payload;try{payload=JSON.parse(text);}catch(_){throw new Error('Сервер черновиков вернул некорректный JSON.');}
  if(response.status===401){window.location.reload();throw new Error('Сессия редактора завершена.');}
  if(!response.ok || payload.success!==true){const e=new Error(payload.message||payload.error||('Ошибка HTTP '+response.status));e.payload=payload;e.status=response.status;throw e;}
  return payload.data||{};
}
async function loadDraftDictionaries(){try{const data=await callDraftsApi('get_dictionaries',{});APP_STATE.draftDictionaries.raw=data.items||data.dictionaries||[];}catch(e){logAction('Справочники черновиков недоступны',{error:String(e.message||e)});}fillSelect(document.getElementById('draft_status_filter'),dictionaryByType('status'),'Все статусы');fillSelect(document.getElementById('draft_workflow_filter'),dictionaryByType('workflow_step'),'Все этапы');fillSelect(document.getElementById('draft_save_status'),dictionaryByType('status'),'Выберите статус');fillSelect(document.getElementById('draft_save_workflow'),dictionaryByType('workflow_step'),'Выберите этап');fillSelect(document.getElementById('draft_save_reason'),dictionaryByType('save_reason'),'manual');fillDoctorSelect(document.getElementById('draft_responsible_filter'),'Все ответственные');fillDoctorSelect(document.getElementById('draft_reviewer_filter'),'Все врачи');fillDoctorSelect(document.getElementById('draft_save_responsible'),'Выберите ответственного');fillDoctorSelect(document.getElementById('draft_save_reviewer'),'Выберите врача');}
function setWorkspace(name){document.body.dataset.workspace=name;document.querySelectorAll('.workspace-tab').forEach(b=>b.classList.toggle('on',b.dataset.workspace===name));document.getElementById('draftsWorkspace')?.classList.toggle('is-hidden',name!=='drafts');if(name==='drafts') refreshDrafts();}
function updateDraftBadge(){const b=document.getElementById('topDraftBadge');if(!b)return;b.textContent=APP_STATE.draft.draft_id ? ('Черновик v'+APP_STATE.draft.version_number+(APP_STATE.draft.is_dirty?' · есть несохранённые изменения':'')) : (APP_STATE.draft.is_dirty?'Новый черновик · есть несохранённые изменения':'Черновик не сохранён');}
async function markDraftDirty(){if(window.__draftApplying)return;APP_STATE.draft.is_dirty=true;updateDraftBadge();}
function draftDueClass(date){if(!date)return '';const today=new Date();today.setHours(0,0,0,0);const d=new Date(date+'T00:00:00');const diff=(d-today)/86400000;if(diff<0)return 'due-overdue';if(diff<=1)return 'due-soon';return '';}
function renderDrafts(items){const box=document.getElementById('draftsList');box.textContent='';if(!items.length){box.innerHTML='<div class="card">Черновики не найдены.</div>';return;}items.forEach(d=>{const card=document.createElement('div');card.className='draft-card'+(APP_STATE.draft.draft_id===d.DRAFT_ID&&APP_STATE.draft.is_dirty?' is-dirty':'');card.innerHTML='<div class="draft-card-head"><div><h3>'+escapeHtml(d.NAME||'Без названия')+'</h3><div class="hint mono">'+escapeHtml(d.CODE||d.DRAFT_ID||'')+'</div></div><div class="badge draft">v'+escapeHtml(d.CURRENT_VERSION||'0')+'</div></div><div class="draft-meta"><div><b>Статус</b>'+escapeHtml(d.STATUS||'—')+'</div><div><b>Этап</b>'+escapeHtml(d.WORKFLOW_STEP||'—')+'</div><div><b>Ответственный</b>'+escapeHtml(d.RESPONSIBLE||'—')+'</div><div><b>Автор</b>'+escapeHtml(d.AUTHOR_NAME||'—')+'</div><div><b>Врач</b>'+escapeHtml(d.REVIEWER_NAME||'—')+'</div><div><b>Срок</b><span class="'+draftDueClass(d.REVIEW_DUE_AT)+'">'+escapeHtml(d.REVIEW_DUE_AT||'—')+'</span></div><div><b>Изменён</b>'+escapeHtml(d.UPDATED_AT||'—')+'</div><div><b>Кто изменил</b>'+escapeHtml(d.UPDATED_BY||'—')+'</div></div><div class="hint">'+escapeHtml(d.LAST_COMMENT||'')+'</div><div class="draft-actions"></div>';const actions=card.querySelector('.draft-actions');[['Открыть','open'],['История','versions'],['Сохранить новую версию','save'],['Удалить','delete']].forEach(([label,act])=>{if((d.IS_DELETED==='TRUE'||d.IS_DELETED===true)&&act==='delete')return;const btn=document.createElement('button');btn.type='button';btn.className=act==='open'?'btn primary':'btn';btn.textContent=label;btn.addEventListener('click',()=>handleDraftCardAction(act,d));actions.appendChild(btn);});if(d.IS_DELETED==='TRUE'||d.IS_DELETED===true){['restore','purge'].forEach(act=>{const btn=document.createElement('button');btn.type='button';btn.className=act==='purge'?'btn ghost':'btn primary';btn.textContent=act==='restore'?'Восстановить':'Удалить окончательно';btn.addEventListener('click',()=>handleDraftCardAction(act,d));actions.appendChild(btn);});}box.appendChild(card);});}
async function refreshDrafts(){const status=document.getElementById('draftsStatus');status.textContent='Загрузка черновиков…';try{const data=await callDraftsApi('list_drafts',{query:fieldValue('draft_search'),status:fieldValue('draft_status_filter'),workflow_step:fieldValue('draft_workflow_filter'),responsible_id:fieldValue('draft_responsible_filter'),reviewer_id:fieldValue('draft_reviewer_filter'),scope:fieldValue('draft_scope_filter')||'active',limit:50,offset:0});APP_STATE.drafts.items=data.items||[];renderDrafts(APP_STATE.drafts.items);status.textContent='Загружено: '+APP_STATE.drafts.items.length;}catch(e){status.textContent='Ошибка: '+String(e.message||e);}}
async function ensureSafeToOpen(){const h=await draftSha256(collectDraftSnapshot().editor_state);const dirty=APP_STATE.draft.is_dirty || (APP_STATE.draft.saved_hash && APP_STATE.draft.saved_hash!==h);if(!dirty)return true;const save=confirm('В текущей статье есть несохранённые изменения.\n\nОК — сохранить новую версию перед загрузкой другого черновика.\nОтмена — выбрать следующий вариант.');if(save){await openDraftSaveModal();return false;}return confirm('Открыть другой черновик без сохранения текущих изменений?');}
async function openDraft(d){if(!(await ensureSafeToOpen()))return;const data=await callDraftsApi('get_draft',{draft_id:d.DRAFT_ID});window.__draftApplying=true;try{applyDraftSnapshot(data.snapshot);const hash=await draftSha256(data.snapshot.editor_state);APP_STATE.draft={draft_id:data.draft.DRAFT_ID,version_id:data.snapshot.version_id||'',version_number:Number(data.draft.CURRENT_VERSION||data.snapshot.version_number||0),saved_hash:data.draft.SNAPSHOT_HASH||hash,loaded_at:new Date().toISOString(),status:data.draft.STATUS||'',is_dirty:false};updateDraftBadge();setWorkspace('editor');}finally{window.__draftApplying=false;}}
async function openDraftSaveModal(){await loadDraftDictionaries();document.getElementById('draft_save_name').value=fieldValue('result_name')||fieldValue('task_name')||'';document.getElementById('draft_save_due').value='';document.getElementById('draft_save_comment').value='';document.getElementById('draft_save_modal').classList.remove('is-hidden');document.getElementById('draft_save_cancel').focus();}
function closeDraftSaveModal(){document.getElementById('draft_save_modal').classList.add('is-hidden');}
function selectedDoctorName(id){return (APP_DATA.doctors.find(d=>String(d.id)===String(id))||{}).name||'';}
async function saveDraftVersion(){const reason=fieldValue('draft_save_reason')||'manual';const comment=fieldValue('draft_save_comment').trim();if(DRAFT_REQUIRED_COMMENT_REASONS.has(reason)&&!comment){alert('Для выбранной причины комментарий обязателен.');return;}if(fieldValue('draft_save_status')==='waiting_medical_review'&&!fieldValue('draft_save_due'))document.getElementById('draftSaveWarning').textContent='Срок медицинской редакции не указан — сохранение не заблокировано.';const snapshot=await collectDraftPayload();const meta={name:fieldValue('draft_save_name')||fieldValue('result_name')||fieldValue('task_name')||'Без названия',code:fieldValue('result_code'),status:fieldValue('draft_save_status'),workflow_step:fieldValue('draft_save_workflow')||String(cur),responsible_id:fieldValue('draft_save_responsible'),responsible:selectedDoctorName(fieldValue('draft_save_responsible')),reviewer_id:fieldValue('draft_save_reviewer')||fieldValue('medical_reviewer_id'),reviewer_name:selectedDoctorName(fieldValue('draft_save_reviewer')||fieldValue('medical_reviewer_id')),review_due_at:fieldValue('draft_save_due'),comment,save_reason:reason,author_id:fieldValue('author_id'),author_name:selectedDoctorName(fieldValue('author_id')),article_type:fieldValue('article_type'),search_intent:fieldValue('search_intent'),article_structure:fieldValue('article_structure'),structure_version:fieldValue('article_structure_version'),section:fieldValue('article_section'),region:fieldValue('region')};const action=APP_STATE.draft.draft_id?'save_draft_version':'create_draft';const data=await callDraftsApi(action,{draft_id:APP_STATE.draft.draft_id,expected_current_version:APP_STATE.draft.version_number,meta,snapshot});APP_STATE.draft={draft_id:data.draft_id,version_id:data.version_id,version_number:Number(data.version_number),saved_hash:data.snapshot_hash||snapshot.snapshot_hash,loaded_at:new Date().toISOString(),status:meta.status,is_dirty:false};updateDraftBadge();closeDraftSaveModal();logAction('Черновик сохранён',{draft_id:data.draft_id,version:data.version_number});alert('Черновик сохранён: версия '+data.version_number);}
async function renderVersions(d){const data=await callDraftsApi('list_versions',{draft_id:d.DRAFT_ID});const box=document.getElementById('draftVersions');box.classList.remove('is-hidden');box.innerHTML='<div class="card"><h3>История версий: '+escapeHtml(d.NAME||'')+'</h3></div>';const inner=box.querySelector('.card');(data.items||[]).forEach(v=>{const row=document.createElement('div');row.className='version-row';row.innerHTML='<b>v'+escapeHtml(v.VERSION_NUMBER)+'</b><div>'+escapeHtml(v.SAVED_AT||'')+' · '+escapeHtml(v.SAVED_BY||'')+'<br><span class="hint">'+escapeHtml(v.SAVE_COMMENT||'')+'</span></div><div class="version-actions"></div>';const a=row.querySelector('.version-actions');[['Открыть без восстановления','open_version'],['Восстановить как новую версию','restore_version']].forEach(([label,act])=>{const btn=document.createElement('button');btn.type='button';btn.className=act==='restore_version'?'btn primary':'btn';btn.textContent=label;btn.addEventListener('click',()=>handleVersionAction(act,d,v));a.appendChild(btn);});inner.appendChild(row);});box.scrollIntoView({behavior:'smooth',block:'start'});}
async function handleVersionAction(act,d,v){if(act==='open_version'){if(!(await ensureSafeToOpen()))return;const data=await callDraftsApi('get_version',{draft_id:d.DRAFT_ID,version_id:v.VERSION_ID});window.__draftApplying=true;try{applyDraftSnapshot(data.snapshot);APP_STATE.draft={draft_id:d.DRAFT_ID,version_id:v.VERSION_ID,version_number:Number(v.VERSION_NUMBER),saved_hash:'',loaded_at:new Date().toISOString(),status:v.STATUS||'',is_dirty:true};updateDraftBadge();setWorkspace('editor');}finally{window.__draftApplying=false;}}else{if(!confirm('Восстановить версию v'+v.VERSION_NUMBER+' как новую текущую версию?'))return;const data=await callDraftsApi('restore_version',{draft_id:d.DRAFT_ID,version_id:v.VERSION_ID,expected_current_version:Number(d.CURRENT_VERSION||0)});alert('Создана новая версия v'+data.version_number);refreshDrafts();renderVersions(d);}}
async function handleDraftCardAction(act,d){if(act==='open')return openDraft(d);if(act==='versions')return renderVersions(d);if(act==='save')return openDraftSaveModal();if(act==='delete'){if(confirm('Черновик будет перемещён в корзину.\nВсе версии сохранятся.')){await callDraftsApi('delete_draft',{draft_id:d.DRAFT_ID});refreshDrafts();}}if(act==='restore'){await callDraftsApi('restore_draft',{draft_id:d.DRAFT_ID});refreshDrafts();}if(act==='purge'){const typed=prompt('Будут удалены реестр черновика и все файлы версий.\nОтменить это действие будет нельзя.\n\nВведите название черновика для подтверждения.');if(typed===d.NAME){await callDraftsApi('purge_draft',{draft_id:d.DRAFT_ID,confirm_name:typed});refreshDrafts();}else if(typed!==null)alert('Название не совпадает.');}}

document.querySelectorAll('.workspace-tab').forEach(btn=>btn.addEventListener('click',()=>setWorkspace(btn.dataset.workspace||'editor')));
document.querySelectorAll('[data-workspace-switch]').forEach(btn=>btn.addEventListener('click',()=>setWorkspace(btn.dataset.workspaceSwitch||'editor')));
document.getElementById('draft_save_cancel')?.addEventListener('click',closeDraftSaveModal);
document.getElementById('draft_save_submit')?.addEventListener('click',()=>saveDraftVersion().catch(e=>alert(String(e.message||e))));
['draft_search','draft_status_filter','draft_workflow_filter','draft_responsible_filter','draft_reviewer_filter','draft_scope_filter'].forEach(id=>document.getElementById(id)?.addEventListener('change',refreshDrafts));
document.getElementById('draft_search')?.addEventListener('keydown',e=>{if(e.key==='Enter')refreshDrafts();});
form?.addEventListener('input',markDraftDirty,true);form?.addEventListener('change',markDraftDirty,true);updateDraftBadge();loadDraftDictionaries();
