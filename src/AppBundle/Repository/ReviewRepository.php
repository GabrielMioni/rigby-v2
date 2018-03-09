<?php

namespace AppBundle\Repository;

use AppBundle\Entity\Review;
use Symfony\Component\Intl\NumberFormatter\NumberFormatter;
use Doctrine\ORM\Tools\Pagination\Paginator;


/**
 * ReviewRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class ReviewRepository extends \Doctrine\ORM\EntityRepository
{
    public function getWeeklyData($date)
    {
        $weekArray = array();

        $startUnix = strtotime($date);

        while (count($weekArray) < 7)
        {
            $weekArray[date('m/d/y', $startUnix)] = '';

            $startUnix += 86400;
        }

        foreach ($weekArray as $dateKey=>$value)
        {
            $format = 'Y-m-d H:i:s';

            $start = date($format, strtotime(date('m/d', strtotime($dateKey))));
            $end   = date($format, strtotime($start) + 86400 - 1);

            $results = $this->createQueryBuilder('review')
                ->andWhere('review.created > :start')
                ->andWhere('review.created < :end')
                ->setParameter('start', new \DateTime($start))
                ->setParameter('end', new \DateTime($end))
                ->getQuery()
                ->execute();

            $weekArray[$dateKey] = count($results);
        }

        $graphData = $this->setWeeklyGraphData($weekArray);

        return json_encode($graphData);
    }

    protected function setWeeklyGraphData(array $weekArray)
    {
        $graphData = array();
        $max = ceil(max($weekArray) / 10) * 10;
        $max = $max < 10 ? 10 : $max;

        $legendIncrement = $max / 5;

        $legendArray = array($legendIncrement*5, $legendIncrement*4, $legendIncrement*3, $legendIncrement*2, $legendIncrement, 0);

        $graphData['legend'] = $legendArray;

        foreach ($weekArray as $dateKey=>$reviewCount)
        {
            $percent = intval($reviewCount) === 0 ? 0 . '%' : number_format( $reviewCount / $max * 100 ) . '%';
            $graphData[$dateKey] = array('percent'=>$percent, 'count'=>$reviewCount, 'day' => date('D', strtotime($dateKey)), 'date'=>date('n/j', strtotime($dateKey)));
        }

        return $graphData;
    }

    public function getWeeklyStats($date)
    {
        $results = $this->createQueryBuilder('review')
            ->orderBy('review.created')
            ->getQuery()
            ->getArrayResult();

        $format = 'Y-m-d H:i:s';
        $pretty = 'D n/j';

        $activeDate   = date($format, strtotime($date));
        $previousDate = date($format, strtotime($date) - 86400);

        $reviewsByDateActive   = $this->getReviewsByDate($activeDate, $results);
        $reviewsByDatePrevious = $this->getReviewsByDate($previousDate, $results);

        $totalReviews = count($results);
        $avgRating    = $this->getAverage($results);
        $lastReview   = isset($results[$totalReviews -1]['created']) ? date('n/j/y h:ia', strtotime($results[$totalReviews -1]['created']->format($format))) : 'None';
        $activeDateCount   = count($reviewsByDateActive);
        $previousDateCount = count($reviewsByDatePrevious);

        return json_encode( array('activeDate'=>date($pretty, strtotime($activeDate)), 'previousDate'=>date($pretty, strtotime($previousDate)), 'totalReviews'=>$totalReviews, 'avg'=>$avgRating, 'lastReview'=>$lastReview, 'activeDateCount'=>$activeDateCount, 'previousDateCount'=>$previousDateCount ));
    }

    function getAverage(array $results)
    {
        if (empty($results))
        {
            return 0;
        }

        $ratings = array();

        foreach ($results as $key=>$value)
        {
            if (isset($value['rating']))
            {
                $ratings[] = $value['rating'];
            }
        }

        return round( array_sum($ratings) / count($ratings), 2 );
    }
    
    function getReviewsByDate($date, array $results)
    {
        $reviewsByDate = array();

        $start = strtotime($date);
        $end   = $start + 86400 - 1;
        
        foreach ($results as $value)
        {
            if (isset($value['created']))
            {
                $unixCreated = strtotime($value['created']->format('Y-m-d H:i:s'));

                if ($unixCreated > $start && $unixCreated < $end)
                {
                    $reviewsByDate[] = $value;
                }
            }
        }

        return $reviewsByDate;
    }

    function getRecentReviews($page = false)
    {
        $start = $page === false ? 0 : intval($page);

        $results = $this->createQueryBuilder('review')
            ->orderBy('review.created')
            ->setFirstResult($start)
            ->setMaxResults(5)
            ->getQuery()
            ->getArrayResult();

        $recentReview = array();

        $format = 'n/j/y - h:ia';

        foreach ($results as $result)
        {
            $stars = '';

            for ($s = 0 ; $s < $result['rating'] ; ++$s)
            {
                $stars .= '<span class="rigby-star"></span>';
            }

            $recentReview[] = array('email'=>$result['reviewerEmail'],
                                    'stars'=> $stars,
                                    'date'=>$result['created']->format($format),
                                    'content'=>$result['reviewContent']
            );
        }

        return json_encode($recentReview);
    }

    function reviewSearch(array $filterArray, $generalSearch = null, $currentPage = 1, $perPage = 10)
    {
        $queryBuilder = $this->createQueryBuilder('review')
            ->orderBy('review.created');

        if ($generalSearch !== null)
        {
            $generalSearch = trim($generalSearch) === '' ? '%' : $this->formatSearchWildcard($generalSearch);

            $queryBuilder->andWhere('review.title LIKE :generalSearch')
                ->orWhere('review.name LIKE :generalSearch')
                ->orWhere('review.email LIKE :generalSearch')
                ->orWhere('review.content LIKE :generalSearch')
                ->orWhere('review.product LIKE :generalSearch')
                ->setParameter('generalSearch', $generalSearch);
        }

        foreach ($filterArray as $filterKey => $filter)
        {
            $inputValue = $this->setInputValue($filter, 'value');
            $inputType  = $this->setInputValue($filter, 'type');
            $inputOperator = $this->setInputValue($filter, 'operator');

            $columns = ['title', 'name', 'email', 'content', 'product', 'created'];

            $type  = in_array($inputType, $columns) ? $inputType : false;

            if ($inputValue === false || $inputType === false ||$inputOperator === false || $type === false)
            {
                break;
            }

            $queryMask = $type . 'Search';

            /*
            if ($type === 'name' || $type ==='email')
            {
                $type = 'reviewer' . ucfirst($type);
            }
            if ($type === 'content' || $type ==='product')
            {
                $type = 'review' . ucfirst($type);
            }
            */

            $value = $inputValue;

            switch ($inputOperator)
            {
                case '1':
                    $operator = $type === 'created' ? '>' : 'LIKE';
                    $value = $operator === '>' ? $inputValue : "%$inputValue%";
                    break;
                case '2':
                    $operator = $type === 'created' ? '<' : 'NOT LIKE';
                    $value = $operator === '<' ? $inputValue : "%$inputValue%";
                    break;
                case '3':
                    $operator = '=';
                    break;
                case '4':
                    $operator = '!=';
                    break;
                case '5':
                    $operator = 'REGEXP';
                    break;
                default:
                    $operator = false;
                    break;
            }

            $filterQuery = "review.$type $operator :$queryMask";

            $queryBuilder->andWhere($filterQuery);
            $queryBuilder->setParameter($queryMask, $value);
        }

        $query = $queryBuilder->getQuery();

        return $this->Paginate($query, $currentPage, $perPage);
    }

    protected function Paginate($query, $currentPage, $perPage)
    {
        $paginator = new Paginator($query);
        $paginator->getQuery()
            ->setFirstResult($perPage * ($currentPage - 1))
            ->setMaxResults($perPage);

        return $paginator;
    }

    protected function setInputValue(array $array, $index)
    {
        $outValue = isset($array[$index]) ? trim($array[$index]) : false;

        if ($outValue !== false && $outValue !== '')
        {
            return $outValue;
        }

        return false;
    }

    protected function formatSearchWildcard($value)
    {
        return '%' . trim( str_replace(' ', '%', $value) ) . '%';
    }

    function updateReview(array $reviewDataArray)
    {
        $qb = $this->createQueryBuilder('r')
            ->update()
            ->set('r.rating', ':rating')
            ->set('r.title', ':title')
            ->set('r.reviewerName', ':name')
            ->set('r.reviewerEmail', ':email')
            ->set('r.reviewContent', ':content')
            ->where('r.id = :id');

        foreach ($reviewDataArray as $key=> $value) {
            $qb->setParameter($key, $value);
        }

        $query = $qb->getQuery();

        return $query->execute();
    }

    function getReviewById($id)
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.rating, r.title, r.reviewerName, r.reviewerEmail, r.reviewContent')
            ->where('r.id = :id')
            ->setParameter('id', $id);
        $query = $qb->getQuery();

        $arrayResult = $query->getArrayResult();

        if (count($arrayResult) > 0)
        {
            $out = array();

            foreach ($arrayResult[0] as $key=>$value)
            {
                $out[$key] = $value;
            }

            return $out;
        }

        return array();
    }
}
