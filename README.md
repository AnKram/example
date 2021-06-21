# example

Написать запрос на Laravel Query Builder или попробовать на Yii2 Active Query

select
    sum(fee) kd,
    sum(fee) / sum(amount) * 100 eff_rate,
    max(crsbrf)  crsbrf,
    max(dbsbrf) dbsbrf,
    count(distinct merchant) count_tst,
    count(distinct d.term_id) count_terminal
  from dic_terminals d
    left join trx_rts_monthly t on t.term_id = d.term_id
  where d.inn = :inn
  group by d.inn
  
  
Laravel Query Builder:
DB::table('dic_terminals')
  ->leftJoin('trx_rts_monthly','dic_terminals.term_id','=','trx_rts_monthly.term_id')
  ->where('dic_terminals.inn','inn')
  ->select(
    DB::raw('sum(fee) AS kd'), 
    DB::raw('max(crsbrf) AS crsbrf'), 
    DB::raw('max(dbsbrf) AS dbsbrf'), 
    DB::raw('count(merchant) AS count_tst'), 
    DB::raw('count(dic_terminals.term_id) AS count_terminal')
  )
  ->groupBy('dic_terminals.inn')
  ->get();
  
Yii2 Active Query:
Yii::app()->db->createCommand()
    ->select('
		sum(fee) kd,
		max(crsbrf) crsbrf,
		max(dbsbrf) dbsbrf,
		count(merchant) count_tst,
		count(dic_terminals.term_id) count_terminal
	')
    ->from('dic_terminals d')
    ->leftJoin('trx_rts_monthly t', 't.term_id = d.term_id')
    ->where('d.inn =:inn')
    ->queryAll();
