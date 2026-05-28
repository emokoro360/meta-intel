/**
 * MetaIntel JavaScript SDK  v2.0  (Images + Videos)
 * @example
 *   import MetaIntelClient, { analyzeMedia } from '@metaintel/sdk';
 *   const client = new MetaIntelClient({ baseUrl: 'https://...', apiKey: 'mi_...' });
 *   const result = await analyzeMedia(client, videoFile);
 *   console.log(result.type);                        // "video"
 *   console.log(result.forensics.deepfake_detected); // false
 */
const VIDEO_EXT = new Set(['.mp4','.mov','.avi','.mkv','.webm','.mpeg','.mpg','.3gp','.flv','.wmv','.m4v','.mxf','.ts']);

export class MetaIntelError extends Error {
  constructor(msg, code=0, data=null){ super(msg); this.name='MetaIntelError'; this.statusCode=code; this.data=data; }
}

class _Http {
  constructor({baseUrl,apiKey,token,timeout=30000}){
    this.base=baseUrl.replace(/\/$/,'')+'/api/v1'; this.apiKey=apiKey||null; this.token=token||null; this.timeout=timeout;
  }
  _h(x={}){
    const h={'Accept':'application/json','X-MetaIntel-SDK':'js/2.0.0',...x};
    if(this.apiKey) h['X-API-Key']=this.apiKey;
    if(this.token)  h['Authorization']=`Bearer ${this.token}`;
    return h;
  }
  async _req(m,p,b=null,isForm=false){
    const ac=new AbortController(); const t=setTimeout(()=>ac.abort(),this.timeout);
    try{
      const r=await fetch(`${this.base}${p}`,{method:m,headers:this._h(isForm?{}:{'Content-Type':'application/json'}),signal:ac.signal,body:b?(isForm?b:JSON.stringify(b)):undefined});
      clearTimeout(t);
      const d=(r.headers.get('content-type')||'').includes('application/json')?await r.json():await r.text();
      if(!r.ok) throw new MetaIntelError(d?.message||d?.error||`HTTP ${r.status}`,r.status,d);
      return d;
    }catch(e){ clearTimeout(t); if(e instanceof MetaIntelError) throw e; if(e.name==='AbortError') throw new MetaIntelError('Timeout',408); throw new MetaIntelError(e.message,0); }
  }
  get(p){return this._req('GET',p);} post(p,b){return this._req('POST',p,b);} upload(p,fd){return this._req('POST',p,fd,true);} delete(p){return this._req('DELETE',p);}
}

class MediaRes {
  constructor(h){this._h=h;}
  async upload(file,opts={}){
    const fd=new FormData(); const name=file instanceof File?file.name:'upload.bin';
    fd.append('file',file instanceof Blob?file:new Blob([file]),name);
    if(opts.runForensics!==undefined)  fd.append('run_forensics', opts.runForensics?'1':'0');
    if(opts.runAi!==undefined)         fd.append('run_ai',        opts.runAi?'1':'0');
    if(opts.maxFrames!==undefined)     fd.append('max_frames',    String(opts.maxFrames));
    return this._h.upload('/media/upload',fd);
  }
  get(id){return this._h.get(`/media/${id}`);}
  getStatus(id){return this._h.get(`/media/${id}/status`);}
  getFrames(id,{suspiciousOnly=false}={}){return this._h.get(`/media/${id}/frames${suspiciousOnly?'?suspicious_only=1':''}`);}
  getGpsTrack(id){return this._h.get(`/media/${id}/gps-track`);}
  reprocess(id,o={}){return this._h.post(`/media/${id}/reprocess`,o);}
  sanitize(id){return this._h.post(`/media/${id}/sanitize`);}
  delete(id){return this._h.delete(`/media/${id}`);}
  list({mediaType,status,page=1,perPage=20}={}){
    let qs=`?page=${page}&per_page=${perPage}`; if(mediaType) qs+=`&type=${mediaType}`; if(status) qs+=`&status=${status}`;
    return this._h.get(`/media${qs}`);
  }
  waitForCompletion(id,{intervalMs=2000,timeoutMs=300000}={}){
    const s=Date.now(); return new Promise((res,rej)=>{
      const poll=async()=>{
        if(Date.now()-s>timeoutMs) return rej(new MetaIntelError('Timeout',408));
        try{ const {status}=await this.getStatus(id); if(status==='completed') return res({status}); if(status==='failed') return rej(new MetaIntelError('Failed',500,{id})); setTimeout(poll,intervalMs); }
        catch(e){rej(e);}
      }; setTimeout(poll,intervalMs);
    });
  }
}

class ForensicsRes {
  constructor(h){this._h=h;}
  get(id){return this._h.get(`/forensics/${id}`);}
  reanalyze(id,o){return this._h.post(`/forensics/${id}/reanalyze`,o);}
  anomalySummary(){return this._h.get('/forensics/summary/anomalies');}
  suspiciousFrames(id){return this._h.get(`/forensics/${id}/frames`);}
  spliceMap(id){return this._h.get(`/forensics/${id}/splice-map`);}
}

export class MetaIntelClient {
  constructor(opts){
    if(!opts.baseUrl) throw new MetaIntelError('baseUrl required');
    const h=new _Http(opts);
    this.media=new MediaRes(h); this.images=this.media;
    this.forensics=new ForensicsRes(h);
    this.batches={list:()=>h.get('/batches'),get:(id)=>h.get(`/batches/${id}`),timeline:(id)=>h.get(`/batches/${id}/timeline`),cancel:(id)=>h.post(`/batches/${id}/cancel`)};
    this.reports={generate:(o)=>h.post('/reports/generate',{title:o.title,image_ids:o.mediaIds,format:o.format||'pdf',sections:o.sections||['metadata','forensics','gps','timeline']}),get:(u)=>h.get(`/reports/${u}`),exportJson:(id)=>h.get(`/reports/export-json/${id}`)};
    this.analytics={dashboard:()=>h.get('/analytics/dashboard')};
    this._h=h;
  }
  setToken(t){this._h.token=t;return this;}
}

export async function analyzeMedia(client,file,opts={}){
  const name=(file instanceof File?file.name:'upload.bin'); const ext='.'+name.split('.').pop().toLowerCase(); const isVid=VIDEO_EXT.has(ext);
  const {media}=await client.media.upload(file,{runForensics:opts.runForensics??true,runAi:opts.runAi??true,maxFrames:opts.maxFrames??48});
  await client.media.waitForCompletion(media.id,{intervalMs:isVid?3000:2000,timeoutMs:isVid?300000:120000});
  const data=await client.media.get(media.id);
  let forensics=null; try{forensics=(await client.forensics.get(media.id)).forensics;}catch{}
  return {type:data.type||'image',media:data.media||data.image,summary:data.summary,metadata_tree:data.metadata_tree,forensics,gps:data.geojson,frames:data.frames||null};
}

export const analyzeImage=analyzeMedia;
export default MetaIntelClient;
