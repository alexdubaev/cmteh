<?php

declare(strict_types=1);
namespace Cmteh;
use InvalidArgumentException;
use PDO;

/** Administrative operations share the native identity and transactional catalog store. */
trait CatalogAdmin
{
    private function recordPath(string $type, string $slug): ?string
    {
        return match ($type) {
            'product','category','brand' => '/' . $type . '/' . $slug,
            'article' => '/articles/' . $slug,
            'page' => '/' . $slug,
            default => null,
        };
    }

    private function choiceQuery(string $type): array
    {
        if (in_array($type, ['product','category','brand'], true)) {
            [$native,$key] = match($type) { 'product'=>['product','product_id'], 'category'=>['category','category_id'], default=>['manufacturer','manufacturer_id'] };
            $sql = 'SELECT x.source_id AS id, x.slug, n.' . $key . ' AS native_id, n.sort_order, ';
            $sql .= $type === 'brand' ? 'n.name AS title, x.status' : 'd.name AS title, n.status';
            $sql .= ', x.data FROM ' . $this->table('cmteh_' . $type) . ' x JOIN ' . $this->table($native) . " n USING ($key)";
            $params=[];
            if ($type !== 'brand') {
                $sql .= ' JOIN ' . $this->table($native . '_description') . " d ON d.$key = n.$key AND d.language_id = ?";
                $params[]=$this->languageId;
            }
            return [$sql,$params];
        }
        if (!in_array($type,self::CONTENT_TYPES,true)) throw new InvalidArgumentException('Неизвестный тип записи');
        return ['SELECT source_id AS id, slug, 0 AS native_id, sort_order, status, data, COALESCE(JSON_UNQUOTE(JSON_EXTRACT(data, \'$.title\')), JSON_UNQUOTE(JSON_EXTRACT(data, \'$.label\')), slug) AS title FROM ' . $this->table('cmteh_content') . ' WHERE type = ?',[$type]];
    }

    public function editRows(string $type, int $page = 1, string $search = '', string $status = ''): array
    {
        $search=CatalogInput::text($search,128,'search');
        if (!in_array($status,['','published','draft','archived','1','0'],true)) throw new InvalidArgumentException('Неизвестный фильтр статуса');
        [$base,$params]=$this->choiceQuery($type);
        $sql='SELECT * FROM (' . $base . ') records WHERE 1=1';
        if ($search !== '') {
            $sql .= " AND (title LIKE ? OR slug LIKE ? OR id LIKE ? OR JSON_UNQUOTE(JSON_EXTRACT(data, '$.sku')) LIKE ?)";
            array_push($params,...array_fill(0,4,'%' . $search . '%'));
            if ($type==='product') {
                // Native SKU/MPN are kept in the normalized catalog columns, not in JSON.
                $sql=substr($sql,0,strrpos($sql,' AND (')) . ' AND (title LIKE ? OR slug LIKE ? OR id LIKE ? OR native_id IN (SELECT product_id FROM ' . $this->table('cmteh_product') . ' WHERE sku LIKE ? OR mpn LIKE ?))';
                $params[]='%' . $search . '%';
            }
        }
        if ($status !== '') {
            $number=in_array($status,['published','1'],true) ? 1 : 0;
            $sql .= ' AND status = ?'; $params[]=$number;
            if (in_array($status,['draft','archived'],true)) {
                $sql .= " AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(data, '$.status')), 'draft') " . ($status==='archived' ? "= 'archived'" : "<> 'archived'");
            }
        }
        $offset=(max(1,min(40000,$page))-1)*25;
        return $this->formatChoices($type,$this->run($sql . " ORDER BY sort_order, native_id, id LIMIT 25 OFFSET $offset",$params)->fetchAll(PDO::FETCH_ASSOC));
    }

    public function editorChoices(string $type): array
    {
        [$sql,$params]=$this->choiceQuery($type);
        return $this->formatChoices($type,$this->run($sql . ' ORDER BY sort_order, native_id, id',$params)->fetchAll(PDO::FETCH_ASSOC));
    }

    private function formatChoices(string $type,array $rows): array
    {
        foreach ($rows as &$row) {
            $data=self::decode($row['data']);
            $row['title']=self::nativeText((string)$row['title'],$data);
            $row['native_id']=(int)$row['native_id'];
            $row['status']=(int)$row['status']===1 ? 'published' : (($data['status']??'draft')==='archived' ? 'archived':'draft');
            $row['path']=$this->recordPath($type,$row['slug']);
            if ($type==='category') {
                $row['parent_slug']=$this->run('SELECT x.slug FROM ' . $this->table('category') . ' c JOIN ' . $this->table('cmteh_category') . ' x ON x.category_id=c.parent_id WHERE c.category_id=?',[$row['native_id']])->fetchColumn() ?: '';
            }
            unset($row['data'],$row['sort_order']);
        }
        return $rows;
    }

    public function editRecord(string $type,string $id): ?array
    {
        $data=$this->storedEditRecord($type,$id);
        if ($data===null) return null;
        if (!in_array($type,['product','category','brand'],true)) return $data;
        [$native,$key]=match($type) {'product'=>['product','product_id'],'category'=>['category','category_id'],default=>['manufacturer','manufacturer_id']};
        $row=$this->run('SELECT n.*, x.slug, x.data AS extra_data' . ($type==='brand' ? ', x.status' : ', d.name, d.description, d.meta_title, d.meta_description') . ' FROM ' . $this->table('cmteh_' . $type) . ' x JOIN ' . $this->table($native) . " n USING ($key)" . ($type==='brand'?'':' JOIN ' . $this->table($native.'_description') . " d ON d.$key=n.$key AND d.language_id=" . $this->languageId) . ' WHERE x.source_id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $extra=self::decode($row['extra_data']);
        $data['native_id']=(int)$row[$key];
        $data['slug']=$row['slug'];
        $data['sort_order']=(int)$row['sort_order'];
        $data['status']=(int)$row['status']===1?'published':(($extra['status']??'draft')==='archived'?'archived':'draft');
        $data[$type==='brand'?'name':'title']=self::nativeText($row['name'],$extra);
        if ($type!=='brand') {
            $data[$type==='product'?'full_description':'description']=self::nativeText($row['description'],$extra);
            $data['seo_title']=self::nativeText($row['meta_title'],$extra);
            $data['seo_description']=self::nativeText($row['meta_description'],$extra);
        }
        $data[$type==='product'?'main_image_path':'image_path']=$row['image'];
        if ($type==='category') $data['parent_slug']=$this->run('SELECT slug FROM ' . $this->table('cmteh_category') . ' WHERE category_id=?',[(int)$row['parent_id']])->fetchColumn() ?: '';
        if ($type==='product') {
            $data['category_slugs']=$this->run('SELECT x.slug FROM ' . $this->table('product_to_category') . ' p JOIN ' . $this->table('cmteh_category') . ' x USING (category_id) WHERE p.product_id=? ORDER BY p.category_id',[$data['native_id']])->fetchAll(PDO::FETCH_COLUMN);
            $data['specification_items']=$this->mergeNativeSpecifications($data['native_id'],$this->specifications($data['native_id'],$data['specification_items']??[]));
        }
        return $data;
    }

    public function saveFromAdmin(string $type,array $input,bool $confirmSlugChange=false): string
    {
        if (!in_array($type,['product','category','brand',...self::CONTENT_TYPES],true)) throw new InvalidArgumentException('Неизвестный тип записи');
        return $this->atomic(function() use($type,$input,$confirmSlugChange):string {
            $id=CatalogInput::text($input['id']??null,160,'id',true);
            // Serialize administrative URL decisions, including collisions with legacy routes.
            $this->run('SELECT version FROM ' . $this->table('cmteh_schema') . ' ORDER BY version FOR UPDATE')->fetchAll();
            $previous=$this->editRecord($type,$id);
            $allowed=['id','slug','status','published_at','is_indexable','sort_order','title','name','description','seo_title','seo_description','canonical_url','image_path','parent_slug','accent','sku','mpn','brand_slug','category_slug','category_slugs','main_image_path','main_image','availability_status','part_type','price_status','price','currency','confirm_zero_price','short_description','full_description','delivery_status','codes','images','documents','specification_items','relations'];
            if (in_array($type,self::CONTENT_TYPES,true)) {
                // Editorial fields are named form fields; internal provenance never comes from a client.
                $allowed=array_merge($allowed,['label','location','url','content','text','h1','intro','sections','cover_image','cover_image_path','og_image','og_image_path','hero_image','hero_image_path','hero_mobile_image_path','home_blocks','brands_mode','brand_slugs','categories_mode','categories_limit','social_links','logo','phones','emails','address','legal_name','legal_details','phone','email','requisites','footer_text','hero_title','hero_text','hero_image_alt','hero_primary_button_url','hero_primary_button_text','hero_secondary_button_url','hero_secondary_button_text','hero_article_link_text','hero_brand_link_text','hero_quick_title','brands_title','brands_link_text','brands_scroll_hint','categories_title','categories_link_text','products_title','products_link_text','articles_title','articles_link_text','date','article_date','published_date','category','author','navigation_items','contacts','company_name','working_hours','copyright','logo_alt','logo_text','logo_url']);
            }
            $row=array_replace($previous??[],array_intersect_key($input,array_flip($allowed)));
            $row['slug']=$input['slug']??$previous['slug']??CatalogInput::slugify($row['title']??$row['name']??$id);
            $row=$this->validate($type,$row);
            $old=$previous ? $this->recordPath($type,$previous['slug']):null;
            $new=$this->recordPath($type,$row['slug']);
            if ($old!==null && $old!==$new && !$confirmSlugChange) throw new InvalidArgumentException('Подтвердите изменение адреса и перенаправление');
            if ($new!==null && $new!==$old) {
                require_once __DIR__ . '/StorefrontPages.php';
                if ($type==='page' && StorefrontPages::reserved($row['slug'])) throw new InvalidArgumentException('Адрес занят системной страницей');
                if ($this->routes($new)!==null) throw new InvalidArgumentException('Адрес уже используется перенаправлением');
                $this->assertSlugAvailable($type,$row);
            }
            if ($type==='product' && !empty($row['brand_slug']) && !$this->taxonomyExists('brand',$row['brand_slug'])) throw new InvalidArgumentException('Выберите существующий бренд');
            $result=$this->save($type,$row);
            if ($old!==null && $new!==$old) {
                $this->run('UPDATE ' . $this->table('cmteh_route') . ' SET target=? WHERE target=? AND status=301',[$new,$old]);
                $this->writeRoute(['path'=>$old,'target'=>$new,'status'=>301]);
                $this->renameReferences($type,$previous['slug'],$row['slug'],$old,$new);
            }
            return $result;
        });
    }

    private function taxonomyId(string $type,string $slug): ?int
    {
        $key=$type==='brand'?'manufacturer_id':'category_id';
        $id=$this->run('SELECT ' . $key . ' FROM ' . $this->table('cmteh_'.$type) . ' WHERE slug=?',[$slug])->fetchColumn();
        return $id===false ? null:(int)$id;
    }

    private function writeAdminNativeFields(string $type,int $id,array $row): void
    {
        $image=$type==='product'?'main_image_path':'image_path';
        if (array_key_exists($image,$row)) {
            $path=$row[$image]===''?'':CatalogInput::publicImagePath($row[$image]);
            if ($path===null) throw new InvalidArgumentException('Изображение: требуется путь catalog/...');
            [$native,$key]=match($type){'product'=>['product','product_id'],'category'=>['category','category_id'],default=>['manufacturer','manufacturer_id']};
            $this->run('UPDATE ' . $this->table($native) . " SET image=? WHERE $key=?",[$path,$id]);
        }
        if ($type==='product' && array_key_exists('category_slugs',$row)) {
            if (!is_array($row['category_slugs']) || !array_is_list($row['category_slugs']) || count($row['category_slugs'])>300) throw new InvalidArgumentException('Категории: неверный список');
            $slugs=$row['category_slugs']; if (!empty($row['category_slug'])) $slugs[]=$row['category_slug'];
            $ids=[];
            foreach(array_unique($slugs) as $slug) $ids[]=$this->taxonomyId('category',CatalogInput::slug($slug))??throw new InvalidArgumentException('Категория не найдена');
            // Preserve unmanaged native assignments whose mapping has not been created yet.
            $this->run('DELETE pc FROM ' . $this->table('product_to_category') . ' pc JOIN ' . $this->table('cmteh_category') . ' c USING(category_id) WHERE pc.product_id=?',[$id]);
            foreach ($ids as $category) $this->run('INSERT IGNORE INTO ' . $this->table('product_to_category') . ' (product_id,category_id) VALUES (?,?)',[$id,$category]);
        }
        if ($type==='category' && array_key_exists('parent_slug',$row)) {
            $parent=empty($row['parent_slug'])?0:($this->taxonomyId('category',CatalogInput::slug($row['parent_slug']))??throw new InvalidArgumentException('Родитель не найден'));
            $parents=$this->run('SELECT category_id,parent_id FROM ' . $this->table('category') . ' FOR UPDATE')->fetchAll(PDO::FETCH_KEY_PAIR);
            $parents[$id]=$parent;
            foreach($parents as $child=>$unused) {
                $seen=[];$path=[];$node=(int)$child;
                while($node!==0) {
                    if(isset($seen[$node])) throw new InvalidArgumentException('Циклическая иерархия категорий');
                    if(!array_key_exists($node,$parents)) throw new InvalidArgumentException('Родитель категории отсутствует');
                    $seen[$node]=true;array_unshift($path,$node);$node=(int)$parents[$node];
                }
                $this->run('DELETE FROM ' . $this->table('category_path') . ' WHERE category_id=?',[$child]);
                foreach($path as $level=>$ancestor) $this->run('INSERT INTO ' . $this->table('category_path') . ' (category_id,path_id,level) VALUES (?,?,?)',[$child,$ancestor,$level]);
            }
            $this->run('UPDATE ' . $this->table('category') . ' SET parent_id=? WHERE category_id=?',[$parent,$id]);
        }
    }

    private function validateHome(array $row): array
    {
        $keys=['hero','brands','categories','products','order','articles','sections'];
        if(isset($row['home_blocks'])) {
            $seen=[];
            foreach($this->objectList($row['home_blocks'],7,'home_blocks') as $block) {
                if(!in_array($block['key']??null,$keys,true) || isset($seen[$block['key']]) || !is_bool($block['enabled']??null) || !is_int($block['sort_order']??null) || abs($block['sort_order'])>10000) throw new InvalidArgumentException('Блок главной: неверные параметры');
                $seen[$block['key']]=true;
            }
        }
        $row['brands_mode']=CatalogInput::enum($row['brands_mode']??'auto',['auto','selected'],'brands_mode');
        $row['categories_mode']=CatalogInput::enum($row['categories_mode']??'auto_roots',['auto_roots','auto_all','selected'],'categories_mode');
        $limit=$row['categories_limit']??8;
        if(!is_int($limit) || $limit<0 || $limit>300) throw new InvalidArgumentException('Лимит категорий: от 0 до 300');
        $row['categories_limit']=$limit;
        foreach(['brand_slugs'=>'brand','category_slugs'=>'category'] as $field=>$type) {
            $slugs=$row[$field]??[];
            if(!is_array($slugs) || !array_is_list($slugs) || count($slugs)>300) throw new InvalidArgumentException('Выбор главной: неверный список');
            foreach($slugs as $slug) if(!$this->taxonomyExists($type,CatalogInput::slug($slug))) throw new InvalidArgumentException('Выбор главной: запись не найдена');
            $row[$field]=array_values(array_unique($slugs));
        }
        return $row;
    }

    private function renameReferences(string $type,string $oldSlug,string $newSlug,string $oldPath,string $newPath): void
    {
        $table=$this->table('cmteh_content');
        foreach($this->run("SELECT type,source_id,data FROM $table FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC) as $record) {
            $data=self::decode($record['data']);$changed=false;
            if(($data['url']??null)===$oldPath) {$data['url']=$newPath;$changed=true;}
            $field=match($type){'brand'=>'brand_slugs','category'=>'category_slugs',default=>null};
            if($field && isset($data[$field]) && in_array($oldSlug,$data[$field],true)) {$data[$field]=array_map(fn($s)=>$s===$oldSlug?$newSlug:$s,$data[$field]);$changed=true;}
            if($changed) $this->run("UPDATE $table SET data=? WHERE type=? AND source_id=?",[self::encode($data),$record['type'],$record['source_id']]);
        }
    }

    public function deleteRecord(string $type,string $id): void
    {
        if(!in_array($type,['product','category','brand','page','article','navigation'],true)) throw new InvalidArgumentException('Системную запись нельзя удалить');
        $this->atomic(function()use($type,$id):void {
            $record=$this->editRecord($type,$id);
            if(!$record) throw new InvalidArgumentException('Запись не найдена');
            require_once __DIR__ . '/StorefrontPages.php';
            if($type==='page' && StorefrontPages::reserved($record['slug'])) throw new InvalidArgumentException('Системную страницу нельзя удалить');
            $nativeId=(int)($record['native_id']??0);
            if($type==='brand' && $this->run('SELECT 1 FROM ' . $this->table('product') . ' WHERE manufacturer_id=? LIMIT 1',[$nativeId])->fetchColumn()) throw new InvalidArgumentException('Бренд связан с товарами; сначала снимите связи');
            if($type==='category' && ($this->run('SELECT 1 FROM ' . $this->table('product_to_category') . ' WHERE category_id=? LIMIT 1',[$nativeId])->fetchColumn() || $this->run('SELECT 1 FROM ' . $this->table('category') . ' WHERE parent_id=? LIMIT 1',[$nativeId])->fetchColumn())) throw new InvalidArgumentException('Категория связана с товарами или дочерними категориями');
            $path=$this->recordPath($type,$record['slug']);
            if($path) {
                $this->run('UPDATE ' . $this->table('cmteh_route') . ' SET status=410,target=NULL WHERE target=?',[$path]);
                $this->writeRoute(['path'=>$path,'status'=>410,'target'=>null]);
            }
            if(in_array($type,['product','category','brand'],true)) {
                [$native,$key]=match($type){'product'=>['product','product_id'],'category'=>['category','category_id'],default=>['manufacturer','manufacturer_id']};
                // Delete only tables carrying this native entity ID; no shared attributes/media are removed.
                $tables=$this->run('SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME=? AND LEFT(TABLE_NAME,?)=?',[$key,strlen($this->prefix),$this->prefix])->fetchAll(PDO::FETCH_COLUMN);
                foreach($tables as $table) {
                    $suffix=substr($table,strlen($this->prefix));
                    if($suffix===$native || str_starts_with($suffix,$native.'_') || $suffix==='cmteh_'.$type) $this->run('DELETE FROM `' . $table . '` WHERE `' . $key . '`=?',[$nativeId]);
                }
                if($type==='product') {
                    $this->run('DELETE FROM ' . $this->table('product_related') . ' WHERE related_id=?',[$nativeId]);
                    foreach($this->run('SELECT product_id,data FROM ' . $this->table('cmteh_product') . ' FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC) as $other) {
                        $data=self::decode($other['data']);
                        $data['relations']=array_values(array_filter($data['relations']??[],fn($r)=>($r['source_id']??null)!==$id));
                        $data['native_related']=array_values(array_filter($data['native_related']??[],fn($v)=>(int)$v!==$nativeId));
                        $this->run('UPDATE ' . $this->table('cmteh_product') . ' SET data=? WHERE product_id=?',[self::encode($data),$other['product_id']]);
                    }
                }
            } else $this->run('DELETE FROM ' . $this->table('cmteh_content') . ' WHERE type=? AND source_id=?',[$type,$id]);
            $this->run('DELETE FROM ' . $this->table('cmteh_media_link') . ' WHERE owner_type=? AND owner_id=?',[$type,$id]);
            $this->removeEditorialReferences($type,$record['slug'],$path);
        });
    }

    private function removeEditorialReferences(string $type,string $slug,?string $path): void
    {
        $table=$this->table('cmteh_content');
        foreach($this->run("SELECT type,source_id,data FROM $table FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC) as $record) {
            $data=self::decode($record['data']);
            if($record['type']==='navigation' && $path!==null && ($data['url']??null)===$path) {
                $data['status']='draft';
                $this->run("UPDATE $table SET status=0,data=? WHERE type=? AND source_id=?",[self::encode($data),$record['type'],$record['source_id']]);
                continue;
            }
            $field=match($type){'brand'=>'brand_slugs','category'=>'category_slugs',default=>null};
            if($field && isset($data[$field])) {
                $data[$field]=array_values(array_filter($data[$field],fn($s)=>$s!==$slug));
                $this->run("UPDATE $table SET data=? WHERE type=? AND source_id=?",[self::encode($data),$record['type'],$record['source_id']]);
            }
        }
    }
}
