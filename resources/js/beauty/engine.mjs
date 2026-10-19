// Booth adapter for CTBot000/face-beautifier ca5ad40 (MIT).
import { Renderer as Pipeline } from './renderer.mjs';
import { MaskPainter } from './mask.mjs';
import { computeFace } from './face.mjs';
import { buildWarps, faceUniforms, blurScaleFor } from './warp.mjs';
import { OneEuroArray } from './onefilter.mjs';

/** Invert bounded upstream backward warps in reverse composition order. */
export function forwardPoint(point,warps,aspect) {
  let p=[point.x*aspect,point.y];
  for(let i=warps.length-1;i>=0;i--) {
    const h=warps[i],original=p;
    if(Math.hypot(p[0]-h.cx,p[1]-h.cy)>=h.r)continue;
    let q=[...p];
    for(let j=0;j<32;j++) {
      const t=Math.min(1,Math.hypot(q[0]-h.cx,q[1]-h.cy)/h.r),fall=1-t*t*(3-2*t);
      const next=h.mode===0?[original[0]+h.dx*fall,original[1]+h.dy*fall]:[h.cx+(original[0]-h.cx)/(1-h.k*fall),h.cy+(original[1]-h.cy)/(1-h.k*fall)];
      if(Math.hypot(next[0]-q[0],next[1]-q[1])<1e-8){q=next;break;}q=next;
    }
    p=q;
  }
  return {x:p[0]/aspect,y:p[1]};
}

export class Engine {
  constructor(canvas,onLoss) {
    this.canvas=canvas;this.meshes=[];this.filters=[];this.painter=new MaskPainter(320);
    this.mask=document.createElement('canvas');this.mask.width=320;this.mask.height=320;
    this.input=document.createElement('canvas');this.disposed=false;this.maskDirty=true;
    this.pipeline=new Pipeline(canvas);this.gl=this.pipeline.gl;this.maskAspect=0;
    this.loss=event=>{event.preventDefault();if(!this.disposed)onLoss();};
    canvas.addEventListener('webglcontextlost',this.loss);
    this.view={compare:-1,showMask:false};this.meshRevision=0;this.cachedGeometry=null;
  }
  setFaces(faces,mapping,still=false) {
    const slots=new Map(mapping.map((id,i)=>[id,i])),old=this.meshes;
    this.meshes=faces.slice(0,4).flatMap((face,i)=>{
      const mesh=new Float32Array(956);
      for(let id=0;id<478;id++) {
        const p=face[slots.get(id)];
        if(!p||!Number.isFinite(p.x)||!Number.isFinite(p.y))return [];
        mesh[id*2]=p.x;mesh[id*2+1]=p.y;
      }
      const filter=this.filters[i]||(this.filters[i]=new OneEuroArray(956,{minCutoff:1.2,beta:.012}));
      if(still||!old[i]||Math.hypot(mesh[2]-old[i][2],mesh[3]-old[i][3])>.15)filter.reset();
      return [new Float32Array(filter.filter(mesh,performance.now()/1000))];
    });
    for(let i=this.meshes.length;i<this.filters.length;i++)this.filters[i].reset();
    this.maskDirty=true;this.meshRevision++;
  }
  geometry(settings,width,height) {
    const aspect=width/height;
    if(this.cachedGeometry?.settings===settings&&this.cachedGeometry.revision===this.meshRevision&&this.cachedGeometry.result.aspect===aspect)return this.cachedGeometry.result;
    const params=globalThis.HmuBeautySettings.toShader(settings.values,settings.colors);
    const faces=this.meshes.map(mesh=>computeFace(mesh,aspect)).filter(Boolean);
    const result={params,faces,warps:faces.flatMap(face=>buildWarps(face,params)),aspect};
    this.cachedGeometry={settings,revision:this.meshRevision,result};return result;
  }
  mapFaces(anchors,prefix,settings,width,height) {
    const {warps,aspect}=this.geometry(settings,width,height);
    return anchors.map(face=>face.map((p,i)=>i<prefix?forwardPoint(p,warps,aspect):p));
  }
  render(source,settings,exporting=false) {
    if(this.disposed)throw new Error('Beauty renderer is closed');
    const sw=source.videoWidth||source.naturalWidth||source.width,sh=source.videoHeight||source.naturalHeight||source.height;
    if(!sw||!sh)return;
    const ratio=exporting?1:Math.min(1,960/Math.max(sw,sh)),w=Math.round(sw*ratio),h=Math.round(sh*ratio);
    this.pipeline.resize(w,h);
    let upload=source;
    if(w!==sw||h!==sh) {
      if(this.input.width!==w||this.input.height!==h){this.input.width=w;this.input.height=h;}
      this.input.getContext('2d').drawImage(source,0,0,w,h);upload=this.input;
    }
    this.pipeline.uploadSource(upload,w,h);
    if(this.maskDirty||this.maskAspect!==w/h) {
      this.painter.setAspect(w/h);this.mask.width=this.painter.canvas.width;this.mask.height=this.painter.canvas.height;
      const ctx=this.mask.getContext('2d');ctx.fillStyle='#000';ctx.fillRect(0,0,this.mask.width,this.mask.height);
      ctx.globalCompositeOperation='lighter';for(const mesh of this.meshes)ctx.drawImage(this.painter.paint(mesh),0,0);
      ctx.globalCompositeOperation='source-over';this.pipeline.uploadMask(this.mask);
      this.maskDirty=false;this.maskAspect=w/h;
    }
    const {params,faces,warps}=this.geometry(settings,w,h);
    this.pipeline.render({params,warps,face:faces.map(face=>faceUniforms(face,1)),blurScale:blurScaleFor(faces[0]||null,h,params.radius),mirror:0,
      compare:exporting?-1:this.view.compare,showMask:exporting?false:this.view.showMask,time:exporting?0:performance.now()/1000});
    if(this.pipeline.glError())throw new Error('GPU không đủ tài nguyên để xử lý ảnh.');
  }
  async export(source,settings) {
    this.render(source,settings,true);
    const blob=await new Promise(resolve=>this.canvas.toBlob(resolve,'image/jpeg',.97));
    if(!blob||blob.size>24000000)throw new Error('Không xuất được ảnh làm đẹp trong giới hạn dung lượng.');
    return new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(reader.result);reader.onerror=reject;reader.readAsDataURL(blob);});
  }
  dispose() {
    if(this.disposed)return;this.disposed=true;
    this.canvas.removeEventListener('webglcontextlost',this.loss);this.pipeline.dispose();
    this.input.width=this.input.height=this.mask.width=this.mask.height=this.painter.canvas.width=this.painter.canvas.height=1;
    this.meshes=[];this.filters=[];
  }
}
